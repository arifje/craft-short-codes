<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\services;

use arjanbrinkman\craftshortcodes\models\Settings;
use Craft;
use craft\base\Field;
use craft\console\Application as ConsoleApplication;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\helpers\ElementHelper;
use craft\models\Section;
use craft\web\Application as WebApplication;
use Stringable;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

class ShortCodeService extends Component
{
    public const FORMAT_ERROR_LENGTH = 'length';
    public const FORMAT_ERROR_CHARACTERS = 'characters';

    public ?Settings $settings = null;

    private bool $settingsResolved = false;
    private ?Settings $validatedSettings = null;

    /** @var array<string, true> */
    private array $loggedWarnings = [];

    public static function normalizeCode(string $code): string
    {
        $normalized = strtoupper(trim($code));

        return preg_replace('/[\s_-]+/', '', $normalized) ?? $normalized;
    }

    public static function normalizeAlphabet(string $alphabet): string
    {
        $alphabet = self::normalizeCode($alphabet);
        $characters = [];

        foreach (str_split($alphabet) as $character) {
            if (!in_array($character, $characters, true)) {
                $characters[] = $character;
            }
        }

        return implode('', $characters);
    }

    public function hasCodeValue(mixed $value): bool
    {
        return self::normalizeCode($this->stringValue($value)) !== '';
    }

    public function getValidatedSettings(): ?Settings
    {
        if ($this->settingsResolved) {
            return $this->validatedSettings;
        }

        $this->settingsResolved = true;

        if (!$this->settings instanceof Settings) {
            $this->logOnce('missing-settings', 'Short Codes could not load its settings model. Entry saves will be left unchanged.');
            return null;
        }

        if (!$this->settings->validate()) {
            $this->logOnce(
                'invalid-settings',
                sprintf(
                    'Short Codes configuration is invalid: %s Entry saves will be left unchanged.',
                    implode(' ', $this->settings->getErrorSummary(true))
                )
            );
            return null;
        }

        $this->validatedSettings = $this->settings;

        return $this->validatedSettings;
    }

    public function generateCode(): string
    {
        $settings = $this->requireSettings();
        $alphabet = $settings->getAlphabet();
        $lastIndex = strlen($alphabet) - 1;
        $code = '';

        for ($position = 0; $position < $settings->getCodeLength(); $position++) {
            $code .= $alphabet[random_int(0, $lastIndex)];
        }

        return $code;
    }

    /** @param array<string, true> $reservedCodes Codes generated in the current process but not persisted yet. */
    public function generateUniqueCode(?int $excludeEntryId = null, array $reservedCodes = []): ?string
    {
        $settings = $this->requireSettings();

        for ($attempt = 0; $attempt < $settings->getMaximumGenerationAttempts(); $attempt++) {
            $code = $this->generateCode();
            if (!isset($reservedCodes[$code]) && $this->isCodeUnique($code, $excludeEntryId)) {
                return $code;
            }
        }

        return null;
    }

    /** @return array<int, self::FORMAT_ERROR_*> */
    public function getFormatErrors(string $code): array
    {
        $settings = $this->requireSettings();
        $errors = [];

        if (strlen($code) !== $settings->getCodeLength()) {
            $errors[] = self::FORMAT_ERROR_LENGTH;
        }

        foreach (str_split($code) as $character) {
            if (!str_contains($settings->getAlphabet(), $character)) {
                $errors[] = self::FORMAT_ERROR_CHARACTERS;
                break;
            }
        }

        return $errors;
    }

    public function isEligible(Entry $entry): bool
    {
        $settings = $this->getValidatedSettings();
        if (!$settings instanceof Settings) {
            return false;
        }

        if (
            $entry->sectionId === null ||
            $entry->isProvisionalDraft ||
            $entry->getIsUnpublishedDraft() ||
            ElementHelper::isDraftOrRevision($entry) ||
            ElementHelper::isDerivative($entry)
        ) {
            return false;
        }

        try {
            $sectionHandle = $this->sectionHandle($entry->getSection());
            $entryTypeHandle = $entry->getType()->handle;
        } catch (InvalidConfigException $exception) {
            $this->logOnce(
                'invalid-entry-configuration',
                sprintf('Short Codes skipped an incompletely configured entry: %s', $exception->getMessage())
            );
            return false;
        }

        if ($sectionHandle === null) {
            return false;
        }

        $sectionHandles = $settings->getSectionHandles();
        if ($sectionHandles !== [] && !in_array($sectionHandle, $sectionHandles, true)) {
            return false;
        }

        $entryTypeHandles = $settings->getEntryTypeHandles();

        return $entryTypeHandles === [] || in_array($entryTypeHandle, $entryTypeHandles, true);
    }

    public function canManageEntry(Entry $entry): bool
    {
        return $this->isEligible($entry) && $this->hasUsableField($entry);
    }

    public function shouldBackfillEntry(Entry $entry): bool
    {
        if (!$this->canManageEntry($entry)) {
            return false;
        }

        $settings = $this->requireSettings();

        return !$this->hasCodeValue($entry->getFieldValue($settings->getFieldHandle()));
    }

    /**
     * Normalizes an existing value or generates an empty one before Craft takes
     * its dirty-field snapshot. Returns false only when the save must stop.
     */
    public function prepareEntryForSave(Entry $entry): bool
    {
        if (!$this->isEligible($entry) || !$this->hasUsableField($entry)) {
            return true;
        }

        $settings = $this->requireSettings();
        $currentValue = $this->stringValue($entry->getFieldValue($settings->getFieldHandle()));
        $normalizedCode = self::normalizeCode($currentValue);

        if ($normalizedCode !== '') {
            if ($normalizedCode !== $currentValue) {
                $entry->setFieldValue($settings->getFieldHandle(), $normalizedCode);
            }

            // Enforce the invariant even when a caller explicitly asks Craft
            // to skip its normal validation pass. A normal validated save will
            // check again in EVENT_BEFORE_VALIDATE immediately before storage.
            return $this->validateCodeForEntry($entry, $normalizedCode);
        }

        $code = $this->generateUniqueCode($entry->getCanonicalId());
        if ($code === null) {
            $entry->addError(
                'field:' . $settings->getFieldHandle(),
                Craft::t('short-codes', 'A unique code could not be generated. Try saving again.')
            );
            Craft::error(
                sprintf(
                    'Short Codes could not generate a unique code after %d attempts for entry %s.',
                    $settings->getMaximumGenerationAttempts(),
                    $entry->id ?? 'new'
                ),
                self::class
            );

            return false;
        }

        $entry->setFieldValue($settings->getFieldHandle(), $code);

        return true;
    }

    /**
     * Adds format and uniqueness errors during Craft's normal validation pass.
     */
    public function validateEntry(Entry $entry): void
    {
        if (!$this->isEligible($entry) || !$this->hasUsableField($entry)) {
            return;
        }

        $settings = $this->requireSettings();
        $code = self::normalizeCode($this->stringValue($entry->getFieldValue($settings->getFieldHandle())));

        // A standalone validation can happen without a save. Generation remains
        // in the before-save event so Craft 5 includes it in the dirty snapshot.
        if ($code === '') {
            return;
        }

        $this->validateCodeForEntry($entry, $code);
    }

    private function validateCodeForEntry(Entry $entry, string $code): bool
    {
        $settings = $this->requireSettings();
        $formatErrors = $this->getFormatErrors($code);
        $isValid = true;

        foreach ($formatErrors as $formatError) {
            if ($formatError === self::FORMAT_ERROR_LENGTH) {
                $isValid = false;
                $entry->addError(
                    'field:' . $settings->getFieldHandle(),
                    Craft::t(
                        'short-codes',
                        'This code must contain exactly {length} characters.',
                        ['length' => $settings->getCodeLength()]
                    )
                );
            } elseif ($formatError === self::FORMAT_ERROR_CHARACTERS) {
                $isValid = false;
                $entry->addError(
                    'field:' . $settings->getFieldHandle(),
                    Craft::t('short-codes', 'This code contains characters that are not allowed.')
                );
            }
        }

        if ($formatErrors === [] && !$this->isCodeUnique($code, $entry->getCanonicalId())) {
            $isValid = false;
            $entry->addError(
                'field:' . $settings->getFieldHandle(),
                Craft::t('short-codes', 'This code is already used by another entry.')
            );
        }

        return $isValid;
    }

    public function isCodeUnique(string $code, ?int $excludeEntryId = null): bool
    {
        $settings = $this->requireSettings();
        $query = $this->baseEntryQuery()
            ->status(null)
            ->site('*')
            ->limit(1);

        if ($excludeEntryId !== null) {
            $query->id(['not', $excludeEntryId]);
        }

        Craft::configure($query, [
            $settings->getFieldHandle() => self::normalizeCode($code),
        ]);

        return !$query->exists();
    }

    public function findEntry(string $code): ?Entry
    {
        $settings = $this->getValidatedSettings();
        if (!$settings instanceof Settings) {
            return null;
        }

        $code = self::normalizeCode($code);
        if ($code === '' || $this->getFormatErrors($code) !== []) {
            return null;
        }

        try {
            $entry = $this->findLiveEntryByCode($code);
            if (!$entry instanceof Entry || trim((string)$entry->getUrl()) === '') {
                return null;
            }

            return $entry;
        } catch (Throwable $exception) {
            Craft::error(
                sprintf('Short Codes lookup failed: %s', $exception->getMessage()),
                self::class
            );

            return null;
        }
    }

    public function addOperationalError(Entry $entry, Throwable $exception): void
    {
        $settings = $this->getValidatedSettings();
        $attribute = $settings instanceof Settings
            ? 'field:' . $settings->getFieldHandle()
            : 'shortCodes';

        $entry->addError(
            $attribute,
            Craft::t('short-codes', 'The code could not be validated. Try again or contact an administrator.')
        );

        Craft::error(
            sprintf(
                'Short Codes failed while processing entry %s: %s',
                $entry->id ?? 'new',
                $exception->getMessage()
            ),
            self::class
        );
    }

    public function getFieldHandle(): ?string
    {
        return $this->getValidatedSettings()?->getFieldHandle();
    }

    public function getCodeLength(): ?int
    {
        return $this->getValidatedSettings()?->getCodeLength();
    }

    /** @return string[] */
    public function getSectionHandles(): array
    {
        return $this->getValidatedSettings()?->getSectionHandles() ?? [];
    }

    /** @return string[] */
    public function getEntryTypeHandles(): array
    {
        return $this->getValidatedSettings()?->getEntryTypeHandles() ?? [];
    }

    /** @return EntryQuery<int, Entry> */
    protected function createEntryQuery(): EntryQuery
    {
        return Entry::find();
    }

    protected function getCurrentSiteId(): int
    {
        return $this->getCraftApplication()->getSites()->getCurrentSite()->id;
    }

    protected function findLiveEntryByCode(string $code): ?Entry
    {
        $settings = $this->requireSettings();
        $query = $this->baseEntryQuery()
            ->status(Entry::STATUS_LIVE)
            ->siteId($this->getCurrentSiteId())
            ->limit(1);

        Craft::configure($query, [
            $settings->getFieldHandle() => $code,
        ]);

        $entry = $query->one();

        return $entry instanceof Entry ? $entry : null;
    }

    protected function hasUsableField(Entry $entry): bool
    {
        return $this->getFieldForEntry($entry) instanceof PlainText;
    }

    /** @return EntryQuery<int, Entry> */
    private function baseEntryQuery(): EntryQuery
    {
        $settings = $this->requireSettings();
        $query = $this->createEntryQuery()
            ->section($settings->getSectionHandles() ?: '*')
            ->drafts(false)
            ->provisionalDrafts(false)
            ->revisions(false)
            ->trashed(false)
            ->ignorePlaceholders();

        if ($settings->getEntryTypeHandles() !== []) {
            $query->type($settings->getEntryTypeHandles());
        }

        return $query;
    }

    private function getFieldForEntry(Entry $entry): ?PlainText
    {
        $settings = $this->getValidatedSettings();
        if (!$settings instanceof Settings) {
            return null;
        }

        $fieldHandle = $settings->getFieldHandle();

        try {
            $fieldLayout = $entry->getFieldLayout();
            $field = $fieldLayout?->getFieldByHandle($fieldHandle);
        } catch (Throwable $exception) {
            $this->logOnce(
                'field-layout-error-' . $fieldHandle,
                sprintf('Short Codes could not inspect field "%s": %s', $fieldHandle, $exception->getMessage())
            );
            return null;
        }

        if ($field === null) {
            $globalField = $this->getCraftApplication()->getFields()->getFieldByHandle($fieldHandle);
            $message = $globalField === null
                ? sprintf('Short Codes skipped entries because the configured field "%s" does not exist.', $fieldHandle)
                : sprintf('Short Codes skipped an entry type because field "%s" is not in its field layout.', $fieldHandle);
            $this->logOnce('missing-field-' . $fieldHandle, $message);
            return null;
        }

        if (!$field instanceof PlainText) {
            $this->logOnce(
                'invalid-field-type-' . $fieldHandle,
                sprintf('Short Codes requires "%s" to be a Plain Text field.', $fieldHandle)
            );
            return null;
        }

        if ($field->translationMethod !== Field::TRANSLATION_METHOD_NONE) {
            $this->logOnce(
                'translated-field-' . $fieldHandle,
                sprintf(
                    'Short Codes requires "%s" to be Not translatable so each entry has one code across all sites. The entry was skipped.',
                    $fieldHandle
                )
            );
            return null;
        }

        return $field;
    }

    private function requireSettings(): Settings
    {
        $settings = $this->getValidatedSettings();
        if (!$settings instanceof Settings) {
            throw new InvalidConfigException('Short Codes configuration is invalid.');
        }

        return $settings;
    }

    private function getCraftApplication(): ConsoleApplication|WebApplication
    {
        $application = Craft::$app;
        if (!$application instanceof ConsoleApplication && !$application instanceof WebApplication) {
            throw new InvalidConfigException('Craft application is not initialized.');
        }

        return $application;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_scalar($value) || $value instanceof Stringable) {
            return (string)$value;
        }

        return '';
    }

    private function sectionHandle(mixed $section): ?string
    {
        return $section instanceof Section ? $section->handle : null;
    }

    private function logOnce(string $key, string $message): void
    {
        if (isset($this->loggedWarnings[$key])) {
            return;
        }

        $this->loggedWarnings[$key] = true;
        Craft::warning($message, self::class);
    }
}
