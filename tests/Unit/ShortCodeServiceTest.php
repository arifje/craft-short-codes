<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\tests\Unit;

use arjanbrinkman\craftshortcodes\models\Settings;
use arjanbrinkman\craftshortcodes\services\ShortCodeService;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ShortCodeServiceTest extends TestCase
{
    public function testGeneratedCodesUseConfiguredAlphabetAndLength(): void
    {
        $service = $this->service(new Settings([
            'codeLength' => 8,
            'alphabet' => 'ABC',
        ]));

        for ($iteration = 0; $iteration < 50; $iteration++) {
            $code = $service->generateCode();
            self::assertSame(8, strlen($code));
            self::assertMatchesRegularExpression('/^[ABC]{8}$/', $code);
        }
    }

    public function testCodeNormalization(): void
    {
        self::assertSame('AB12X', ShortCodeService::normalizeCode("  ab-12 x_ \n"));
    }

    public function testExistingCodeIsPreserved(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', '7K4MP', fieldDirty: false);
        $entry->expects(self::never())->method('setFieldValue');

        self::assertTrue($service->prepareEntryForSave($entry));
        $service->validateEntry($entry);
        self::assertNull($service->lastUniquenessCode);
    }

    public function testManualCodeIsNormalizedBeforeSave(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', ' 7k-4_mp ');
        $entry->expects(self::once())
            ->method('setFieldValue')
            ->with('shortCode', '7K4MP');

        self::assertTrue($service->prepareEntryForSave($entry));
    }

    public function testEmptyEligibleEntryReceivesCode(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', '', firstSave: true);
        $entry->expects(self::once())
            ->method('setFieldValue')
            ->with(
                'shortCode',
                self::callback(static fn(string $code): bool =>
                    strlen($code) === 5 && preg_match('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{5}$/', $code) === 1
                )
            );

        self::assertTrue($service->prepareEntryForSave($entry));
    }

    public function testExistingEmptyEntryDoesNotReceiveCodeDuringSave(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', '');
        $entry->expects(self::never())->method('setFieldValue');

        self::assertTrue($service->prepareEntryForSave($entry));
        self::assertNull($service->lastUniquenessCode);
    }

    public function testResaveJobDoesNotCheckExistingCode(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', '7K4MP', resaving: true);
        $entry->expects(self::never())->method('setFieldValue');

        self::assertTrue($service->prepareEntryForSave($entry));
        $service->validateEntry($entry);
        self::assertNull($service->lastUniquenessCode);
    }

    public function testIneligibleSectionDoesNotReceiveCode(): void
    {
        $service = $this->service();
        $entry = $this->entry('news', '');
        $entry->expects(self::never())->method('setFieldValue');

        self::assertTrue($service->prepareEntryForSave($entry));
    }

    public function testDraftDoesNotReceiveCode(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', '', true, false);
        $entry->expects(self::never())->method('setFieldValue');

        self::assertTrue($service->prepareEntryForSave($entry));
    }

    public function testRevisionDoesNotReceiveCode(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', '', false, true);
        $entry->expects(self::never())->method('setFieldValue');

        self::assertTrue($service->prepareEntryForSave($entry));
    }

    public function testDuplicateManualCodeFailsValidationAndExcludesCurrentEntry(): void
    {
        $service = $this->service();
        $service->unique = false;
        $entry = $this->entry('media', 'AB234');
        $entry->expects(self::once())
            ->method('addError')
            ->with('field:shortCode', 'This code is already used by another entry.');

        $service->validateEntry($entry);

        self::assertSame(42, $service->lastExcludedEntryId);
        self::assertSame('AB234', $service->lastUniquenessCode);
    }

    public function testDuplicateManualCodeStopsSaveWhenNormalValidationIsDisabled(): void
    {
        $service = $this->service();
        $service->unique = false;
        $entry = $this->entry('media', 'AB234');
        $entry->expects(self::once())
            ->method('addError')
            ->with('field:shortCode', 'This code is already used by another entry.');

        self::assertFalse($service->prepareEntryForSave($entry));
    }

    public function testInvalidCharactersFailValidation(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', 'AB@24');
        $entry->expects(self::once())
            ->method('addError')
            ->with('field:shortCode', 'This code contains characters that are not allowed.');

        $service->validateEntry($entry);
        self::assertNull($service->lastUniquenessCode);
    }

    public function testIncorrectCodeLengthFailsValidation(): void
    {
        $service = $this->service();
        $entry = $this->entry('media', 'AB24');
        $entry->expects(self::once())
            ->method('addError')
            ->with('field:shortCode', 'This code must contain exactly 5 characters.');

        $service->validateEntry($entry);
        self::assertNull($service->lastUniquenessCode);
    }

    public function testLookupReturnsExpectedEntryUsingNormalizedCode(): void
    {
        $service = $this->service();
        $matchingEntry = $this->entry('media', '7K4MP', false, false, '/articles/example');
        $service->lookupResult = $matchingEntry;

        self::assertSame($matchingEntry, $service->findEntry(' 7k-4_mp '));
        self::assertSame('7K4MP', $service->lastLookupCode);
    }

    public function testLookupReturnsNullForUnknownCode(): void
    {
        $service = $this->service();

        self::assertNull($service->findEntry('7K4MP'));
        self::assertSame('7K4MP', $service->lastLookupCode);
    }

    public function testBackfillSkipsEntriesThatAlreadyHaveACode(): void
    {
        $service = $this->service();
        $existingEntry = $this->entry('media', ' 7k-4_mp ');
        $emptyEntry = $this->entry('media', ' - _ ');

        self::assertFalse($service->shouldBackfillEntry($existingEntry));
        self::assertTrue($service->shouldBackfillEntry($emptyEntry));
    }

    private function service(?Settings $settings = null): TestableShortCodeService
    {
        return new TestableShortCodeService([
            'settings' => $settings ?? new Settings(),
        ]);
    }

    /**
     * @return Entry&MockObject
     */
    private function entry(
        string $sectionHandle,
        mixed $fieldValue,
        bool $isDraft = false,
        bool $isRevision = false,
        ?string $url = '/articles/example',
        bool $fieldDirty = true,
        bool $firstSave = false,
        bool $resaving = false,
    ): Entry {
        $section = $this->getMockBuilder(Section::class)
            ->disableOriginalConstructor()
            ->getMock();
        $section->handle = $sectionHandle;

        $entryType = $this->getMockBuilder(EntryType::class)
            ->disableOriginalConstructor()
            ->getMock();
        $entryType->handle = 'article';

        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'addError',
                'getCanonicalId',
                'getFieldValue',
                'getIsDerivative',
                'getIsDraft',
                'getIsRevision',
                'getIsUnpublishedDraft',
                'getRootOwner',
                'getSection',
                'getType',
                'getUrl',
                'isFieldDirty',
                'setFieldValue',
            ])
            ->getMock();

        $entry->id = 42;
        $entry->sectionId = 1;
        $entry->isProvisionalDraft = false;
        $entry->firstSave = $firstSave;
        $entry->resaving = $resaving;
        $entry->method('getCanonicalId')->willReturn(42);
        $entry->method('getFieldValue')->with('shortCode')->willReturn($fieldValue);
        $entry->method('getIsDerivative')->willReturn($isDraft || $isRevision);
        $entry->method('getIsDraft')->willReturn($isDraft);
        $entry->method('getIsRevision')->willReturn($isRevision);
        $entry->method('getIsUnpublishedDraft')->willReturn($isDraft);
        $entry->method('getRootOwner')->willReturn($entry);
        $entry->method('getSection')->willReturn($section);
        $entry->method('getType')->willReturn($entryType);
        $entry->method('getUrl')->willReturn($url);
        $entry->method('isFieldDirty')->with('shortCode')->willReturn($fieldDirty);

        return $entry;
    }
}

final class TestableShortCodeService extends ShortCodeService
{
    public bool $unique = true;
    public ?int $lastExcludedEntryId = null;
    public ?string $lastUniquenessCode = null;
    public ?Entry $lookupResult = null;
    public ?string $lastLookupCode = null;

    public function isCodeUnique(string $code, ?int $excludeEntryId = null): bool
    {
        $this->lastUniquenessCode = $code;
        $this->lastExcludedEntryId = $excludeEntryId;

        return $this->unique;
    }

    protected function findLiveEntryByCode(string $code): ?Entry
    {
        $this->lastLookupCode = $code;

        return $this->lookupResult;
    }

    protected function hasUsableField(Entry $entry): bool
    {
        return true;
    }
}
