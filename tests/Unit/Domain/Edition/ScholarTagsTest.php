<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Edition;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ScholarTags;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use PHPUnit\Framework\TestCase;

final class ScholarTagsTest extends TestCase
{
    public function testConferenceTitleIsbnAndPublisher(): void
    {
        $edition = Edition::fromArray(1, EditionTest::validInput(['name' => ['uk' => 'Конференція "Освіта"', 'en' => 'Education']]), null, 'UTC', new ValidationErrors());
        $tags = ScholarTags::of($edition, 'en', 'uk', 'Інститут & Ко');
        self::assertSame('<meta name="citation_conference_title" content="Education"/>', $tags['googleScholarJournalTitle']);
        self::assertSame('<meta name="citation_isbn" content="9780306406157"/>', $tags['googleScholarIsbn']);
        self::assertSame('<meta name="citation_publisher" content="Інститут &amp; Ко"/>', $tags['googleScholarPublisher']);
        self::assertStringContainsString('&quot;Освіта&quot;', ScholarTags::of($edition, 'pl', 'uk', null)['googleScholarJournalTitle']);
    }

    public function testNothingWithoutData(): void
    {
        $edition = Edition::fromArray(1, EditionTest::validInput(['isbn' => '']), null, 'UTC', new ValidationErrors());
        self::assertSame(['googleScholarJournalTitle'], array_keys(ScholarTags::of($edition, 'uk', 'uk', '')));
    }
}
