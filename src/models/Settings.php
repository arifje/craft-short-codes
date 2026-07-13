<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\models;

use arjanbrinkman\craftshortcodes\services\ShortCodeService;
use Craft;
use craft\base\Model;

/**
 * File-based settings loaded from config/short-codes.php.
 *
 * Properties intentionally use mixed types so malformed environment-specific
 * configuration can be reported by validation instead of causing a TypeError
 * while Craft is bootstrapping the plugin.
 */
class Settings extends Model
{
    public const MIN_CODE_LENGTH = 3;
    public const MAX_CODE_LENGTH = 32;
    public const MAXIMUM_GENERATION_ATTEMPTS = 10000;

    /** @var mixed Expected type: string */
    public mixed $fieldHandle = 'shortCode';

    /** @var mixed Expected type: string[] */
    public mixed $sectionHandles = ['media', 'sponsored'];

    /** @var mixed Expected type: string[] */
    public mixed $entryTypeHandles = [];

    /** @var mixed Expected type: int */
    public mixed $codeLength = 5;

    /** @var mixed Expected type: string */
    public mixed $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** @var mixed Expected type: int */
    public mixed $maximumGenerationAttempts = 100;

    public function beforeValidate(): bool
    {
        if (is_string($this->fieldHandle)) {
            $this->fieldHandle = trim($this->fieldHandle);
        }

        if (is_string($this->alphabet)) {
            $this->alphabet = ShortCodeService::normalizeAlphabet($this->alphabet);
        }

        $this->sectionHandles = $this->normalizeHandles($this->sectionHandles);
        $this->entryTypeHandles = $this->normalizeHandles($this->entryTypeHandles);

        return parent::beforeValidate();
    }

    /** @return array<int, mixed> */
    public function defineRules(): array
    {
        return [
            [['fieldHandle', 'alphabet', 'codeLength', 'maximumGenerationAttempts'], 'required'],
            [['fieldHandle', 'alphabet'], 'string'],
            [['fieldHandle'], 'match', 'pattern' => '/^[A-Za-z][A-Za-z0-9_]*$/'],
            [['sectionHandles', 'entryTypeHandles'], 'validateHandles'],
            [['codeLength'], 'integer', 'min' => self::MIN_CODE_LENGTH, 'max' => self::MAX_CODE_LENGTH],
            [['maximumGenerationAttempts'], 'integer', 'min' => 1, 'max' => self::MAXIMUM_GENERATION_ATTEMPTS],
            [['alphabet'], 'match', 'pattern' => '/^[A-Z0-9]+$/'],
            [['alphabet'], 'validateAlphabet'],
        ];
    }

    public function validateAlphabet(string $attribute): void
    {
        if (!is_string($this->$attribute)) {
            return;
        }

        if (strlen($this->$attribute) < 2) {
            $this->addError(
                $attribute,
                Craft::t('short-codes', 'The alphabet must contain at least two unique characters.')
            );
        }
    }

    public function validateHandles(string $attribute): void
    {
        $handles = $this->$attribute;
        if (!is_array($handles)) {
            $this->addError(
                $attribute,
                Craft::t('short-codes', 'Handles must be provided as an array of valid, non-empty Craft handles.')
            );
            return;
        }

        foreach ($handles as $handle) {
            if (!is_string($handle) || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $handle) !== 1) {
                $this->addError(
                    $attribute,
                    Craft::t('short-codes', 'Handles must be provided as an array of valid, non-empty Craft handles.')
                );
                return;
            }
        }
    }

    public function getFieldHandle(): string
    {
        return (string)$this->fieldHandle;
    }

    /** @return string[] */
    public function getSectionHandles(): array
    {
        return is_array($this->sectionHandles) ? array_values($this->sectionHandles) : [];
    }

    /** @return string[] */
    public function getEntryTypeHandles(): array
    {
        return is_array($this->entryTypeHandles) ? array_values($this->entryTypeHandles) : [];
    }

    public function getCodeLength(): int
    {
        return (int)$this->codeLength;
    }

    public function getAlphabet(): string
    {
        return (string)$this->alphabet;
    }

    public function getMaximumGenerationAttempts(): int
    {
        return (int)$this->maximumGenerationAttempts;
    }

    public function attributeLabels(): array
    {
        return [
            'fieldHandle' => Craft::t('short-codes', 'Field handle'),
            'sectionHandles' => Craft::t('short-codes', 'Section handles'),
            'entryTypeHandles' => Craft::t('short-codes', 'Entry type handles'),
            'codeLength' => Craft::t('short-codes', 'Code length'),
            'alphabet' => Craft::t('short-codes', 'Alphabet'),
            'maximumGenerationAttempts' => Craft::t('short-codes', 'Maximum generation attempts'),
        ];
    }

    private function normalizeHandles(mixed $handles): mixed
    {
        if (!is_array($handles)) {
            return $handles;
        }

        $normalized = [];
        foreach ($handles as $handle) {
            $handle = is_string($handle) ? trim($handle) : $handle;
            if (!in_array($handle, $normalized, true)) {
                $normalized[] = $handle;
            }
        }

        return $normalized;
    }
}
