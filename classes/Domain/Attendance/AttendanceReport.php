<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Attendance;

/**
 * An attendance report of an online meeting (ATT-02, EVT-05): the CSV
 * that Microsoft Teams, Zoom or Google Meet give after a meeting.
 *
 * The header row is found anywhere in the file (Teams puts a summary
 * above it) by an e-mail column and a duration column. The file may be
 * UTF-8 or UTF-16 (Teams), separated by ",", ";" or tabs. A person who
 * joined several times gets the sum of the durations.
 */
final class AttendanceReport
{
    public const MAX_BYTES = 5_000_000;
    public const MAX_ROWS = 20000;

    private const EMAIL_HEADER = '/e-?mail|пошт|адреса електрон|upn|участник.*почт/iu';
    private const DURATION_HEADER = '/duration|тривал|minutes|хвилин|time in (the )?meeting|час у нараді|час участі|attendance time/iu';

    /**
     * @param array<string, int> $minutes lowercased e-mail => whole minutes
     */
    private function __construct(
        public readonly array $minutes,
        /** data rows that had no e-mail or no readable duration */
        public readonly int $skipped,
        public readonly int $rows,
    ) {
    }

    /** Null when no header with an e-mail and a duration column is found. */
    public static function parse(string $content): ?self
    {
        $text = self::toUtf8($content);
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $delimiter = null;
        $emailCol = $durationCol = -1;
        $minutes = [];
        $skipped = $rows = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if ($delimiter === null) {
                foreach (["\t", ';', ','] as $d) {
                    $cells = str_getcsv($line, $d, '"', '');
                    [$e, $t] = self::headerColumns($cells);
                    if ($e >= 0 && $t >= 0) {
                        $delimiter = $d;
                        $emailCol = $e;
                        $durationCol = $t;
                        break;
                    }
                }
                continue;
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            if (count($cells) <= max($emailCol, $durationCol)) {
                // A new section of a Teams report, or a stray line.
                continue;
            }
            if (++$rows > self::MAX_ROWS) {
                break;
            }
            $email = mb_strtolower(trim((string) $cells[$emailCol]));
            $duration = self::minutes((string) $cells[$durationCol]);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $duration === null) {
                $skipped++;
                continue;
            }
            $minutes[$email] = ($minutes[$email] ?? 0) + $duration;
        }
        if ($delimiter === null) {
            return null;
        }
        return new self(array_map(static fn (float $m): int => (int) floor($m + 1e-9), $minutes), $skipped, $rows);
    }

    /**
     * @param list<string|null> $cells
     * @return array{0: int, 1: int}
     */
    private static function headerColumns(array $cells): array
    {
        $email = $duration = -1;
        foreach ($cells as $i => $cell) {
            $name = trim((string) $cell, " \t\"\u{FEFF}");
            if ($email < 0 && preg_match(self::EMAIL_HEADER, $name)) {
                $email = $i;
            } elseif ($duration < 0 && preg_match(self::DURATION_HEADER, $name)) {
                $duration = $i;
            }
        }
        return [$email, $duration];
    }

    /**
     * Minutes from "75", "75.5", "01:15:30", "1:15", "1h 15m 30s",
     * "1 год 15 хв", "75 mins". Null when nothing is recognized.
     */
    public static function minutes(string $value): ?float
    {
        $v = mb_strtolower(trim($value));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $v)) {
            return (float) str_replace(',', '.', $v);
        }
        if (preg_match('/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $v, $m)) {
            return isset($m[3]) ? (int) $m[1] * 60 + (int) $m[2] + (int) $m[3] / 60 : (int) $m[1] * 60 + (int) $m[2];
        }
        $total = 0.0;
        $found = false;
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(h|hr|hrs|hour|hours|год|годин[аи]?|г\.?|m|min|mins|minute|minutes|хв|хвилин[аи]?|s|sec|secs|second|seconds|с|сек|секунд[аи]?)(?![a-zа-яіїєґ])/u', $v, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $n = (float) str_replace(',', '.', $m[1]);
                $unit = $m[2];
                $found = true;
                if (preg_match('/^(h|hr|hrs|hour|hours|год|годин|година|години|г)/u', $unit)) {
                    $total += $n * 60;
                } elseif (preg_match('/^(s|sec|secs|second|seconds|с|сек|секунд)/u', $unit)) {
                    $total += $n / 60;
                } else {
                    $total += $n;
                }
            }
        }
        return $found ? $total : null;
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($content, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            return (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        return $content;
    }
}
