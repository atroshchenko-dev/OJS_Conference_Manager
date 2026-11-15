<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use DateTimeZone;

/**
 * EDN-10: the fields that the TIB Conference plugin keeps on an OJS issue
 * and their link to the edition, so that the dates, the format and the
 * venue are entered once. The edition owns the dates (with times and
 * zone), the online flag and the street/place; the city and country of
 * TIB are left to TIB.
 */
final class TibFields
{
    public const BEGIN = 'conferenceDateBegin';
    public const END = 'conferenceDateEnd';
    public const STREET = 'conferencePlaceStreet';
    public const CITY = 'conferencePlaceCity';
    public const COUNTRY = 'conferencePlaceCountry';
    public const ONLINE = 'conferenceOnline';

    /** Fields written from the edition. */
    public const SYNCED = [self::BEGIN, self::END, self::STREET, self::ONLINE];

    /** Times of day for a date first set in TIB, in the zone of the edition. */
    private const DEFAULT_TIME = [self::BEGIN => '09:00', self::END => '18:00'];

    /**
     * Values of the TIB fields of the edition's issue.
     *
     * @return array<string, string> keys of SYNCED
     */
    public static function fromEdition(Edition $edition, string $primaryLocale): array
    {
        return [
            self::BEGIN => self::day($edition->date('startsAt'), $edition->timezone),
            self::END => self::day($edition->date('endsAt'), $edition->timezone),
            self::STREET => (string) ($edition->text('venue')->toArray()[$primaryLocale] ?? ''),
            self::ONLINE => $edition->format === EventFormat::Online ? '1' : '',
        ];
    }

    /**
     * Changes of the edition after the TIB fields of its issue were edited:
     * input overrides for the edition, empty when nothing differs. An empty
     * TIB date never clears a date of the edition; a bad one is ignored.
     *
     * @param array<string, mixed> $tib
     * @return array<string, mixed>
     */
    public static function changes(Edition $edition, array $tib, string $primaryLocale): array
    {
        $current = self::fromEdition($edition, $primaryLocale);
        $out = [];
        foreach ([self::BEGIN => 'startsAt', self::END => 'endsAt'] as $field => $date) {
            $day = trim((string) ($tib[$field] ?? ''));
            if ($day === '' || $day === $current[$field] || !self::isDay($day)) {
                continue;
            }
            $local = DateInput::toLocal($edition->date($date), $edition->timezone);
            $out[$date] = $day . 'T' . ($local === null ? self::DEFAULT_TIME[$field] : substr($local, 11, 5));
        }
        $street = trim((string) ($tib[self::STREET] ?? ''));
        if (array_key_exists(self::STREET, $tib) && $street !== $current[self::STREET]) {
            $out['venue'] = array_merge($edition->text('venue')->toArray(), [$primaryLocale => $street]);
        }
        if (array_key_exists(self::ONLINE, $tib)) {
            $online = !in_array($tib[self::ONLINE], [null, '', '0', 0, false], true);
            if ($online && $edition->format !== EventFormat::Online) {
                $out['format'] = EventFormat::Online->value;
            } elseif (!$online && $edition->format === EventFormat::Online) {
                $out['format'] = EventFormat::InPerson->value;
            }
        }
        return $out;
    }

    private static function day(?DateTimeImmutable $date, string $timezone): string
    {
        $local = DateInput::toLocal($date, $timezone);
        return $local === null ? '' : substr($local, 0, 10);
    }

    private static function isDay(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
