<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\tests\Unit;

use arjanbrinkman\craftshortcodes\models\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultFieldHandleIsGeneric(): void
    {
        self::assertSame('shortCode', (new Settings())->getFieldHandle());
    }

    public function testAlphabetNormalizationRemovesDuplicateCharacters(): void
    {
        $settings = new Settings([
            'alphabet' => 'a-a b_b',
        ]);

        self::assertTrue($settings->validate(), implode(' ', $settings->getErrorSummary(true)));
        self::assertSame('AB', $settings->getAlphabet());
    }

    public function testAlphabetRequiresTwoUniqueCharacters(): void
    {
        $settings = new Settings([
            'alphabet' => 'a-a-a',
        ]);

        self::assertFalse($settings->validate());
        self::assertNotEmpty($settings->getErrors('alphabet'));
    }

    public function testEmptyHandleMembersAreRejected(): void
    {
        $settings = new Settings([
            'sectionHandles' => [''],
            'entryTypeHandles' => [null],
        ]);

        self::assertFalse($settings->validate());
        self::assertNotEmpty($settings->getErrors('sectionHandles'));
        self::assertNotEmpty($settings->getErrors('entryTypeHandles'));
    }
}
