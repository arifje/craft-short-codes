<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\variables;

use arjanbrinkman\craftshortcodes\Plugin;
use arjanbrinkman\craftshortcodes\services\ShortCodeService;
use craft\elements\Entry;
use Stringable;

class ShortCodesVariable
{
    public function findEntry(mixed $code): ?Entry
    {
        return Plugin::getInstance()->getShortCodes()->findEntry($this->stringValue($code));
    }

    public function normalize(mixed $code): string
    {
        return ShortCodeService::normalizeCode($this->stringValue($code));
    }

    public function getFieldHandle(): ?string
    {
        return Plugin::getInstance()->getShortCodes()->getFieldHandle();
    }

    public function getCodeLength(): ?int
    {
        return Plugin::getInstance()->getShortCodes()->getCodeLength();
    }

    /** @return string[] */
    public function getSectionHandles(): array
    {
        return Plugin::getInstance()->getShortCodes()->getSectionHandles();
    }

    /** @return string[] */
    public function getEntryTypeHandles(): array
    {
        return Plugin::getInstance()->getShortCodes()->getEntryTypeHandles();
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string)$value;
        }

        return '';
    }
}
