<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA;

/**
 * РНОКПП (реєстраційний номер облікової картки платника податків), JUR-05:
 * ten digits, the last one is the check digit of the first nine.
 */
final class Rnokpp
{
    private const WEIGHTS = [-1, 5, 7, 9, 4, 6, 10, 5, 7];

    public static function isValid(string $code): bool
    {
        if (preg_match('/^\d{10}$/', $code) !== 1) {
            return false;
        }
        return self::checkDigit(substr($code, 0, 9)) === (int) $code[9];
    }

    /** The check digit of nine digits. */
    public static function checkDigit(string $nine): int
    {
        $sum = 0;
        foreach (self::WEIGHTS as $i => $w) {
            $sum += (int) $nine[$i] * $w;
        }
        return (($sum % 11) + 11) % 11 % 10;
    }
}
