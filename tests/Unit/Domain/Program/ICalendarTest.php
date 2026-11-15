<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Program;

use APP\plugins\generic\conferenceManager\classes\Domain\Program\ICalendar;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ICalendarTest extends TestCase
{
    public function testEventsInUtc(): void
    {
        $kyiv = new DateTimeZone('Europe/Kyiv');
        $ics = ICalendar::build('Конференція', [[
            'uid' => 'session-1@example.org',
            'start' => new DateTimeImmutable('2027-06-20 09:30', $kyiv),
            'end' => new DateTimeImmutable('2027-06-20 10:30', $kyiv),
            'summary' => 'Секція 1; педагогіка, освіта',
            'location' => 'Аудиторія 101',
            'description' => "Рядок 1\nРядок 2",
            'url' => 'https://example.org/program#cm-session-1',
        ]], new DateTimeImmutable('2026-11-15 10:00', new DateTimeZone('UTC')));
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("DTSTART:20270620T063000Z\r\n", $ics);
        self::assertStringContainsString("DTEND:20270620T073000Z\r\n", $ics);
        self::assertStringContainsString('SUMMARY:Секція 1\; педагогіка\, освіта', $ics);
        self::assertStringContainsString('DESCRIPTION:Рядок 1\nРядок 2', $ics);
        self::assertStringNotContainsString("\n\n", $ics);
    }

    public function testFoldKeepsCharacters(): void
    {
        $line = 'SUMMARY:' . str_repeat('Ж', 100);
        $folded = ICalendar::fold($line);
        foreach (explode("\r\n", $folded) as $part) {
            self::assertLessThanOrEqual(75, strlen($part));
            self::assertTrue(mb_check_encoding(ltrim($part, ' '), 'UTF-8'), 'no split inside a character');
        }
        self::assertSame($line, str_replace("\r\n ", '', $folded));
    }
}
