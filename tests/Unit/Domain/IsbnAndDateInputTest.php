<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\Isbn;
use PHPUnit\Framework\TestCase;

final class IsbnAndDateInputTest extends TestCase
{
    public function testIsbn13(): void
    {
        self::assertTrue(Isbn::isValid13('978-617-7652-04-4'));
        self::assertTrue(Isbn::isValid13('978-0-306-40615-7'));
        self::assertTrue(Isbn::isValid13('979 10 90636 07 1'));
        self::assertFalse(Isbn::isValid13('978-0-306-40615-8'));
        self::assertFalse(Isbn::isValid13('0-306-40615-2'), 'ISBN-10 is not accepted');
        self::assertFalse(Isbn::isValid13('977-0-306-40615-7'));
        self::assertSame('9780306406157', Isbn::normalize(' 978-0-306 40615-7 '));
    }

    public function testLocalTimeIsConvertedToUtcWithDst(): void
    {
        // Kyiv is UTC+2 in winter and UTC+3 in summer.
        self::assertSame('2027-01-15T08:00:00Z', DateInput::toIsoUtc(DateInput::parse('2027-01-15 10:00', 'Europe/Kyiv') ?: null));
        self::assertSame('2027-07-15T07:00:00Z', DateInput::toIsoUtc(DateInput::parse('2027-07-15T10:00', 'Europe/Kyiv') ?: null));
        self::assertSame('2027-03-31T21:00:00Z', DateInput::toIsoUtc(DateInput::parse('2027-04-01', 'Europe/Kyiv') ?: null));
    }

    public function testOffsetMeansAbsoluteMoment(): void
    {
        self::assertSame('2027-01-15T10:00:00Z', DateInput::toIsoUtc(DateInput::parse('2027-01-15T10:00:00Z', 'Europe/Kyiv') ?: null));
        self::assertSame('2027-01-15T09:00:00Z', DateInput::toIsoUtc(DateInput::parse('2027-01-15T10:00+01:00', 'Europe/Kyiv') ?: null));
    }

    public function testEmptyAndInvalid(): void
    {
        self::assertNull(DateInput::parse('', 'UTC'));
        self::assertNull(DateInput::parse(null, 'UTC'));
        self::assertFalse(DateInput::parse('2027-02-30 10:00', 'UTC'));
        self::assertFalse(DateInput::parse('15.01.2027', 'UTC'));
        self::assertFalse(DateInput::parse(['x'], 'UTC'));
    }

    public function testStorageRoundTripAndLocal(): void
    {
        $date = DateInput::parse('2027-07-15 10:00', 'Europe/Kyiv') ?: null;
        $stored = DateInput::toStorage($date);
        self::assertSame('2027-07-15 07:00:00', $stored);
        self::assertSame('2027-07-15T10:00', DateInput::toLocal(DateInput::fromStorage($stored), 'Europe/Kyiv'));
        self::assertTrue(DateInput::isValidTimezone('Europe/Kyiv'));
        self::assertFalse(DateInput::isValidTimezone('Mars/Olympus'));
    }
}
