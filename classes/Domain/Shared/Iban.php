<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Shared;

/**
 * IBAN checks by ISO 13616 (mod 97). Country-specific length rules
 * live in jurisdiction profiles, not here.
 */
final class Iban
{
    public static function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        // Piecewise mod 97 to avoid big integers.
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder . $chunk)) % 97;
        }
        return $remainder === 1;
    }

    public static function countryCode(string $iban): string
    {
        return substr(self::normalize($iban), 0, 2);
    }
}
