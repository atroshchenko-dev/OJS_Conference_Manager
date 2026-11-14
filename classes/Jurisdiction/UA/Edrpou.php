<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA;

/**
 * ЄДРПОУ code of a legal entity: 8 digits with a check digit.
 *
 * Weights 1..7 are used for codes below 30000000 or above 60000000,
 * otherwise 7,1..6. If the remainder mod 11 is 10, the weights are
 * increased by 2 and the remainder is recalculated; a second 10 gives 0.
 */
final class Edrpou
{
    public static function isValid(string $code): bool
    {
        if (!preg_match('/^\d{8}$/', $code)) {
            return false;
        }
        $digits = array_map('intval', str_split($code));
        $number = (int) $code;
        $weights = ($number < 30000000 || $number > 60000000)
            ? [1, 2, 3, 4, 5, 6, 7]
            : [7, 1, 2, 3, 4, 5, 6];

        $check = self::weightedMod11($digits, $weights);
        if ($check === 10) {
            $check = self::weightedMod11($digits, array_map(static fn (int $w): int => $w + 2, $weights));
            if ($check === 10) {
                $check = 0;
            }
        }
        return $check === $digits[7];
    }

    /**
     * @param list<int> $digits
     * @param list<int> $weights
     */
    private static function weightedMod11(array $digits, array $weights): int
    {
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $digits[$i] * $w;
        }
        return $sum % 11;
    }
}
