<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Jurisdiction;

use APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA\Edrpou;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EdrpouTest extends TestCase
{
    /** @return iterable<array{string, bool}> */
    public static function cases(): iterable
    {
        // Public codes of well-known legal entities.
        yield 'Taras Shevchenko National University of Kyiv' => ['02070944', true];
        yield 'PrivatBank' => ['14360570', true];
        // Codes in the 30000000–60000000 range use weights 7,1..6.
        yield 'middle range valid' => [self::withCheckDigit('3456789'), true];
        // Codes needing the second pass (remainder 10 with the first weights).
        yield 'second pass' => [self::secondPassExample(), true];
        yield 'wrong check digit' => ['02070945', false];
        yield 'seven digits' => ['0207094', false];
        yield 'letters' => ['0207094A', false];
    }

    /** @dataProvider cases */
    #[DataProvider('cases')]
    public function testValidation(string $code, bool $valid): void
    {
        self::assertSame($valid, Edrpou::isValid($code));
    }

    public function testExactlyOneCheckDigitIsValid(): void
    {
        $valid = array_filter(range(0, 9), static fn (int $d): bool => Edrpou::isValid('0207094' . $d));
        self::assertSame([4], array_values($valid));
    }

    /** Reference implementation written independently from the class. */
    private static function withCheckDigit(string $seven): string
    {
        $n = (int) ($seven . '0');
        $w = ($n < 30000000 || $n > 60000000) ? [1, 2, 3, 4, 5, 6, 7] : [7, 1, 2, 3, 4, 5, 6];
        $sum = 0;
        foreach (str_split($seven) as $i => $d) {
            $sum += (int) $d * $w[$i];
        }
        $c = $sum % 11;
        if ($c === 10) {
            $sum = 0;
            foreach (str_split($seven) as $i => $d) {
                $sum += (int) $d * ($w[$i] + 2);
            }
            $c = $sum % 11;
            $c = $c === 10 ? 0 : $c;
        }
        return $seven . $c;
    }

    private static function secondPassExample(): string
    {
        for ($i = 1000000; $i < 2000000; $i++) {
            $seven = str_pad((string) $i, 7, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split($seven) as $k => $d) {
                $sum += (int) $d * ($k + 1);
            }
            if ($sum % 11 === 10) {
                return self::withCheckDigit($seven);
            }
        }
        self::fail('no example found');
    }
}
