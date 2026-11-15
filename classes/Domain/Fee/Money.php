<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

use InvalidArgumentException;

/**
 * An amount in minor units (kopiyky, cents) of an ISO 4217 currency.
 * Financial code never uses floating point numbers (FEE-03).
 */
final class Money
{
    /** Currencies whose minor unit is not 1/100 (ISO 4217). */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public function __construct(public readonly int $minor, public readonly string $currency)
    {
        if (!self::isCurrency($currency)) {
            throw new InvalidArgumentException('Not an ISO 4217 code: ' . $currency);
        }
    }

    public static function isCurrency(string $code): bool
    {
        return (bool) preg_match('/^[A-Z]{3}$/', $code);
    }

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[$currency] ?? 2;
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Parses what a person types: "150", "150.5", "150,50", "1 500,00".
     * Returns null for anything else, including more decimals than the
     * currency has and negative amounts.
     */
    public static function parse(string|int $input, string $currency): ?self
    {
        if (!self::isCurrency($currency)) {
            return null;
        }
        $exp = self::exponent($currency);
        if (is_int($input)) {
            return $input < 0 ? null : new self($input * (10 ** $exp), $currency);
        }
        $s = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($input));
        if (!preg_match('/^(\d{1,12})(?:[.,](\d+))?$/', $s, $m)) {
            return null;
        }
        $fraction = $m[2] ?? '';
        if (strlen($fraction) > $exp) {
            return null;
        }
        $minor = (int) $m[1] * (10 ** $exp) + ($exp === 0 ? 0 : (int) str_pad($fraction, $exp, '0'));
        return new self($minor, $currency);
    }

    /** "150.00" — the decimal form for forms and exports, without rounding. */
    public function decimal(): string
    {
        $exp = self::exponent($this->currency);
        if ($exp === 0) {
            return (string) $this->minor;
        }
        $sign = $this->minor < 0 ? '-' : '';
        $abs = (string) abs($this->minor);
        $abs = str_pad($abs, $exp + 1, '0', STR_PAD_LEFT);
        return $sign . substr($abs, 0, -$exp) . '.' . substr($abs, -$exp);
    }

    public function times(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    public function plus(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException('Currencies differ');
        }
        return new self($this->minor + $other->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    /** @return array{minor: int, currency: string, decimal: string} */
    public function toArray(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency, 'decimal' => $this->decimal()];
    }
}
