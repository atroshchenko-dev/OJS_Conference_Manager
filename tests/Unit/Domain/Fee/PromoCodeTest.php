<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Fee;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PromoCode;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use PHPUnit\Framework\TestCase;

final class PromoCodeTest extends TestCase
{
    /** @param array<string, mixed> $input */
    private static function build(array $input): array
    {
        $errors = new ValidationErrors();
        return [PromoCode::fromInput(null, 1, $input, $errors), $errors];
    }

    public function testValidation(): void
    {
        [$code, $e] = self::build(['code' => ' early-bird ', 'percent' => '20', 'maxUses' => '', 'validUntil' => '2027-03-01']);
        self::assertSame([], $e->toArray());
        self::assertSame('EARLY-BIRD', $code?->code);
        self::assertNull($code?->maxUses);
        [, $e] = self::build(['code' => 'ab', 'percent' => '0']);
        self::assertTrue($e->has('code'));
        self::assertTrue($e->has('percent'));
        [, $e] = self::build(['code' => 'КОД2027', 'discountType' => 'amount', 'amount' => '0', 'currency' => 'UAH']);
        self::assertTrue($e->has('code'), 'Latin letters only');
        self::assertTrue($e->has('amount'));
        [, $e] = self::build(['code' => 'DATES', 'percent' => 5, 'validFrom' => '2027-03-02', 'validUntil' => '2027-03-01', 'maxUses' => '-1']);
        self::assertTrue($e->has('validUntil'));
        self::assertTrue($e->has('maxUses'));
        [, $e] = self::build(['code' => 'DATES', 'percent' => 5, 'validFrom' => '2027-02-30']);
        self::assertTrue($e->has('validFrom'));
    }

    public function testDiscount(): void
    {
        $fee = new Money(15000, 'UAH');
        self::assertSame(12000, (new PromoCode(null, 1, 'P20', 20, null))->apply($fee)->minor);
        self::assertSame(0, (new PromoCode(null, 1, 'FREE', 100, null))->apply($fee)->minor);
        // 33 % of 100.01: the discount is rounded up, in favour of the payer.
        self::assertSame(6700, (new PromoCode(null, 1, 'P33', 33, null))->apply(new Money(10001, 'UAH'))->minor);
        self::assertSame(10000, (new PromoCode(null, 1, 'MINUS50', null, new Money(5000, 'UAH')))->apply($fee)->minor);
        self::assertSame(0, (new PromoCode(null, 1, 'BIG', null, new Money(50000, 'UAH')))->apply($fee)->minor);
        self::assertSame(15000, (new PromoCode(null, 1, 'EUR', null, new Money(500, 'EUR')))->apply($fee)->minor, 'another currency does not apply');
        self::assertSame('50.00 UAH', (new PromoCode(null, 1, 'MINUS50', null, new Money(5000, 'UAH')))->discountText());
    }

    public function testWhenUsable(): void
    {
        $code = new PromoCode(1, 1, 'X', 10, null, 2, '2027-02-01', '2027-02-28', true, '', 1);
        self::assertNull($code->problemOn('2027-02-01'));
        self::assertNull($code->problemOn('2027-02-28'));
        self::assertStringEndsWith('notYet', (string) $code->problemOn('2027-01-31'));
        self::assertStringEndsWith('expired', (string) $code->problemOn('2027-03-01'));
        self::assertStringEndsWith('usedUp', (string) (new PromoCode(1, 1, 'X', 10, null, 2, null, null, true, '', 2))->problemOn('2027-02-10'));
        self::assertStringEndsWith('inactive', (string) (new PromoCode(1, 1, 'X', 10, null, null, null, null, false))->problemOn('2027-02-10'));
    }
}
