<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\Iban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IbanTest extends TestCase
{
    /** @return iterable<array{string, bool}> */
    public static function cases(): iterable
    {
        yield 'UA bank' => ['UA743052990000026007233566001', true];
        yield 'UA with spaces, lower case' => ['ua74 3052 9900 0002 6007 2335 6600 1', true];
        yield 'UA treasury' => ['UA118201720000031000000000001', true];
        yield 'DE' => ['DE89370400440532013000', true];
        yield 'one digit changed' => ['UA743052990000026007233566002', false];
        yield 'too short' => ['UA00', false];
        yield 'garbage' => ['not an iban', false];
    }

    /** @dataProvider cases */
    #[DataProvider('cases')]
    public function testValidation(string $iban, bool $valid): void
    {
        self::assertSame($valid, Iban::isValid($iban));
    }

    public function testNormalize(): void
    {
        self::assertSame('UA743052990000026007233566001', Iban::normalize(' ua74 3052 9900 0002 6007 2335 6600 1 '));
        self::assertSame('UA', Iban::countryCode('ua74 3052'));
    }
}
