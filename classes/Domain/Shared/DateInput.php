<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses dates entered by people. A value with an offset ("Z", "+02:00")
 * is an absolute moment; a value without one ("2027-03-01 18:00",
 * "2027-03-01T18:00", "2027-03-01") is local time in the given zone.
 * A date without time means 00:00 local time of that day.
 * Results are always in UTC.
 */
final class DateInput
{
    public const STORAGE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @return DateTimeImmutable|null|false null for empty input, false for invalid input
     */
    public static function parse(mixed $value, string $timezone): DateTimeImmutable|null|false
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'));
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            $zone = new DateTimeZone('UTC');
        }

        $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value);
        $formats = $hasOffset
            ? ['Y-m-d\TH:i:sP', 'Y-m-d\TH:iP', 'Y-m-d H:i:sP', 'Y-m-d H:iP', 'Y-m-d\TH:i:s.uP']
            : ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', '!Y-m-d'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $zone);
            $warnings = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($warnings === false || ($warnings['warning_count'] === 0 && $warnings['error_count'] === 0))) {
                return $date->setTimezone(new DateTimeZone('UTC'));
            }
        }
        return false;
    }

    /** Value as stored in the database (UTC). */
    public static function toStorage(?DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new DateTimeZone('UTC'))->format(self::STORAGE_FORMAT);
    }

    /** Value read from the database (UTC). */
    public static function fromStorage(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(self::STORAGE_FORMAT, substr($value, 0, 19), new DateTimeZone('UTC'));
        return $date === false ? null : $date;
    }

    public static function toIsoUtc(?DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** Local wall time for forms, "Y-m-d\TH:i" (the HTML datetime-local format). */
    public static function toLocal(?DateTimeImmutable $date, string $timezone): ?string
    {
        if ($date === null) {
            return null;
        }
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            $zone = new DateTimeZone('UTC');
        }
        return $date->setTimezone($zone)->format('Y-m-d\TH:i');
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return $timezone === 'UTC' || in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }
}
