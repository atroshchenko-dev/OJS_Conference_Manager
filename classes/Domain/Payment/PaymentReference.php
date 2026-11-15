<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

/**
 * The code of an invoice for the payment purpose (FEE-05): 8 characters
 * without look-alikes (no 0/O, 1/I/L), the last one a check character,
 * so a typo in a bank statement is noticed.
 */
final class PaymentReference
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function generate(): string
    {
        $body = '';
        for ($i = 0; $i < 7; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $body . self::check($body);
    }

    public static function isValid(string $code): bool
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[' . self::ALPHABET . ']{8}$/', $code)) {
            return false;
        }
        return substr($code, 7) === self::check(substr($code, 0, 7));
    }

    private static function check(string $body): string
    {
        $n = strlen(self::ALPHABET);
        $sum = 0;
        foreach (str_split($body) as $i => $c) {
            $sum += ($i + 1) * (int) strpos(self::ALPHABET, $c);
        }
        return self::ALPHABET[$sum % $n];
    }
}
