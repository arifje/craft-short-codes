<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\console\controllers;

use arjanbrinkman\craftshortcodes\Plugin;
use Craft;
use craft\console\Application as ConsoleApplication;
use craft\console\Controller;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\helpers\Db;
use Throwable;
use yii\console\ExitCode;

final class BackfillController extends Controller
{
    public bool $dryRun = false;
    public ?int $limit = null;
    public ?string $section = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'dryRun',
            'limit',
            'section',
        ]);
    }

    /** @return array<string, string> */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'd' => 'dryRun',
            'l' => 'limit',
            's' => 'section',
        ]);
    }

    public function actionIndex(): int
    {
        $service = Plugin::getInstance()->getShortCodes();
        $settings = $service->getValidatedSettings();
        if ($settings === null) {
            $this->stderr("Short Codes configuration is invalid. See the Craft log for details.\n");
            return ExitCode::CONFIG;
        }
        $fieldHandle = $settings->getFieldHandle();

        if ($this->limit !== null && $this->limit < 1) {
            $this->stderr("--limit must be greater than zero.\n");
            return ExitCode::USAGE;
        }

        $section = trim((string)$this->section);
        if ($section !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $section) !== 1) {
            $this->stderr("--section must be a valid Craft handle.\n");
            return ExitCode::USAGE;
        }

        $configuredSections = $settings->getSectionHandles();
        if ($section !== '' && $configuredSections !== [] && !in_array($section, $configuredSections, true)) {
            $this->stderr(sprintf(
                "Section \"%s\" is not allowed by config/short-codes.php.\n",
                $section
            ));
            return ExitCode::USAGE;
        }

        $effectiveSections = $section !== '' ? [$section] : $configuredSections;
        try {
            $query = $this->createQuery($effectiveSections);
            $available = (int)(clone $query)->count();
        } catch (Throwable $exception) {
            Craft::error('Short Codes could not build the backfill query: ' . $exception->getMessage(), self::class);
            $this->stderr("The backfill query could not be created. See the Craft log for details.\n");
            return ExitCode::CONFIG;
        }

        $total = $available;

        if ($this->limit === null) {
            $this->stdout(sprintf(
                "%s short codes across %d configured entr%s.\n",
                $this->dryRun ? 'Dry-running' : 'Backfilling',
                $total,
                $total === 1 ? 'y' : 'ies'
            ));
        } else {
            $this->stdout(sprintf(
                "%s up to %d missing short code%s across %d configured entr%s.\n",
                $this->dryRun ? 'Dry-running' : 'Backfilling',
                $this->limit,
                $this->limit === 1 ? '' : 's',
                $total,
                $total === 1 ? 'y' : 'ies'
            ));
        }

        if ($total === 0) {
            $this->stdout("Nothing to process.\n");
            return ExitCode::OK;
        }

        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;
        $attempted = 0;
        $reservedCodes = [];

        try {
            foreach (Db::each($query, 100) as $entry) {
                if (!$entry instanceof Entry) {
                    continue;
                }

                if ($this->limit !== null && $attempted >= $this->limit) {
                    break;
                }

                $processed++;

                try {
                    if (!$service->shouldBackfillEntry($entry)) {
                        $skipped++;
                        $this->reportProgress($processed, $total, $generated, $skipped, $failed);
                        continue;
                    }

                    $attempted++;

                    $code = $service->generateUniqueCode($entry->getCanonicalId(), $reservedCodes);
                    if ($code === null) {
                        $failed++;
                        $this->stderr(sprintf(
                            "Entry %s: no unique code could be generated.\n",
                            $entry->id ?? 'new'
                        ));
                    } elseif ($this->dryRun) {
                        $reservedCodes[$code] = true;
                        $generated++;
                    } else {
                        $entry->setFieldValue($fieldHandle, $code);

                        if ($this->getCraftApplication()->getElements()->saveElement($entry)) {
                            $generated++;
                        } else {
                            $failed++;
                            $errors = $entry->getErrorSummary(true);
                            $this->stderr(sprintf(
                                "Entry %s (%s): %s\n",
                                $entry->id ?? 'new',
                                $entry->title ?? 'Untitled',
                                $errors === [] ? 'save failed without a validation message' : implode(' ', $errors)
                            ));
                        }
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    Craft::error(
                        sprintf('Short Codes backfill failed for entry %s: %s', $entry->id ?? 'new', $exception->getMessage()),
                        self::class
                    );
                    $this->stderr(sprintf(
                        "Entry %s: %s\n",
                        $entry->id ?? 'new',
                        $exception->getMessage()
                    ));
                }

                $this->reportProgress($processed, $total, $generated, $skipped, $failed);
            }
        } catch (Throwable $exception) {
            $failed++;
            Craft::error('Short Codes backfill stopped while reading a batch: ' . $exception->getMessage(), self::class);
            $this->stderr("Backfill stopped while reading entries. See the Craft log for details.\n");
        }

        $this->stdout(sprintf(
            "Finished. Inspected: %d; attempted: %d; generated: %d; skipped: %d; failed: %d.\n",
            $processed,
            $attempted,
            $generated,
            $skipped,
            $failed
        ));

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * @param string[] $sectionHandles
     * @return EntryQuery<int, Entry>
     */
    private function createQuery(array $sectionHandles): EntryQuery
    {
        $settings = Plugin::getInstance()->getShortCodes()->getValidatedSettings();
        $query = Entry::find()
            ->section($sectionHandles ?: '*')
            ->status(null)
            ->site('*')
            ->unique()
            ->drafts(false)
            ->provisionalDrafts(false)
            ->revisions(false)
            ->trashed(false)
            ->ignorePlaceholders()
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($settings !== null && $settings->getEntryTypeHandles() !== []) {
            $query->type($settings->getEntryTypeHandles());
        }

        return $query;
    }

    private function reportProgress(int $processed, int $total, int $generated, int $skipped, int $failed): void
    {
        if ($processed % 100 !== 0 && $processed !== $total) {
            return;
        }

        $this->stdout(sprintf(
            "Processed %d/%d (generated: %d, skipped: %d, failed: %d).\n",
            $processed,
            $total,
            $generated,
            $skipped,
            $failed
        ));
    }

    private function getCraftApplication(): ConsoleApplication
    {
        $application = Craft::$app;
        if (!$application instanceof ConsoleApplication) {
            throw new \RuntimeException('Craft console application is not initialized.');
        }

        return $application;
    }
}
