<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Program;

use DateTimeImmutable;
use DateTimeZone;

/**
 * PRG-06: an iCalendar file (RFC 5545) with one event per session. Times
 * are written in UTC, so calendars show them in the zone of the reader.
 */
final class ICalendar
{
    /**
     * @param list<array{uid: string, start: DateTimeImmutable, end: DateTimeImmutable, summary: string, location?: string, description?: string, url?: string}> $events
     */
    public static function build(string $name, array $events, DateTimeImmutable $now): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//conferenceManager//Program//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::escape($name)];
        $stamp = self::utc($now);
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::escape($e['uid']);
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . self::utc($e['start']);
            $lines[] = 'DTEND:' . self::utc($e['end']);
            $lines[] = 'SUMMARY:' . self::escape($e['summary']);
            foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $key => $prop) {
                if (($e[$key] ?? '') !== '') {
                    $lines[] = $prop . ':' . self::escape($e[$key]);
                }
            }
            if (($e['url'] ?? '') !== '') {
                $lines[] = 'URL:' . str_replace(["\r", "\n"], '', $e['url']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    public static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $text);
    }

    /** Lines longer than 75 octets go on continuation lines, never inside a UTF-8 character. */
    public static function fold(string $line): string
    {
        $out = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = 74;
        }
        $out[] = $line;
        return implode("\r\n ", $out);
    }

    private static function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }
}
