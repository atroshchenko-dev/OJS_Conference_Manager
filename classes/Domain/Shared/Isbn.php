<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Shared;

/**
 * ISBN-13 (EDN-05). Hyphens and spaces are allowed in input and dropped.
 */
final class Isbn
{
    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[\s-]+/', '', strtoupper(trim($value)));
    }

    public static function isValid13(string $value): bool
    {
        $digits = self::normalize($value);
        if (!preg_match('/^97[89]\d{10}$/', $digits)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return $sum % 10 === 0;
    }
}
