<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Certificate;

/** "{series}-{year}-{seq}" (CRT-04), the sequence without gaps per context and year. */
final class CertificateNumber
{
    public const DEFAULT_SERIES = 'CERT';

    public static function format(string $series, int $year, int $seq): string
    {
        return self::series($series) . '-' . $year . '-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /** Letters (Latin or Cyrillic) and digits, 1–10 characters; anything else gives the default. */
    public static function series(string $series): string
    {
        $s = mb_strtoupper(trim($series));
        return preg_match('/^[\p{L}\p{N}]{1,10}$/u', $s) ? $s : self::DEFAULT_SERIES;
    }

    public static function isValidSeries(string $series): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N}]{1,10}$/u', mb_strtoupper(trim($series)));
    }
}
