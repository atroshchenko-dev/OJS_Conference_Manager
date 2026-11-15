<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Fee;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\MaterialType as M;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\PricingMode as P;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Quote;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Tariff;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\TariffCalculator;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\TariffQuery;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Reference fee models (FEE-02). Pilot 1 reproduces the published call of
 * a university conference; pilots 2 and 3 are typical models of an
 * in-service teacher training institute and a research institute and are
 * to be replaced by the real calls of the pilot institutions.
 */
final class TariffCalculatorTest extends TestCase
{
    private static int $id = 0;

    private static function t(int $category, M $material, P $pricing, string $amount, string $currency = 'UAH', array $opts = []): Tariff
    {
        return new Tariff(
            ++self::$id,
            $category,
            $material,
            $pricing,
            Money::parse($amount, $currency) ?? throw new \LogicException($amount),
            $opts['presentation'] ?? null,
            $opts['min'] ?? null,
            $opts['max'] ?? null,
            isset($opts['from']) ? new DateTimeImmutable($opts['from']) : null,
            isset($opts['to']) ? new DateTimeImmutable($opts['to']) : null,
        );
    }

    private static function q(array $tariffs, int $category, M $material, ?int $pages = null, string $at = '2027-02-01T10:00:00Z', ?PresentationType $type = null): Quote
    {
        return (new TariffCalculator())->quote($tariffs, new TariffQuery($category, $material, new DateTimeImmutable($at), $pages, $type));
    }

    private static function amount(Quote $q): string
    {
        return $q->isOk() ? $q->total?->decimal() . ' ' . $q->total?->currency : (string) $q->problem;
    }

    /** University conference: free abstracts for own staff and partners, per page for papers, foreign authors in USD. */
    public function testPilot1University(): void
    {
        [$own, $partners, $otherFaculty, $foreign] = [1, 2, 3, 4];
        $tariffs = [
            self::t($own, M::Abstract, P::Flat, '0'),
            self::t($partners, M::Abstract, P::Flat, '0'),
            self::t($otherFaculty, M::Abstract, P::Flat, '150'),
            self::t($otherFaculty, M::Paper, P::PerPage, '100'),
            self::t($foreign, M::Any, P::PerPage, '5', 'USD'),
        ];
        self::assertSame('0.00 UAH', self::amount(self::q($tariffs, $own, M::Abstract)));
        self::assertTrue(self::q($tariffs, $partners, M::Abstract)->isFree());
        self::assertSame('150.00 UAH', self::amount(self::q($tariffs, $otherFaculty, M::Abstract)));
        self::assertSame('800.00 UAH', self::amount(self::q($tariffs, $otherFaculty, M::Paper, 8)));
        self::assertSame('30.00 USD', self::amount(self::q($tariffs, $foreign, M::Paper, 6)));
        self::assertSame('10.00 USD', self::amount(self::q($tariffs, $foreign, M::Abstract, 2)), '"any" covers abstracts too');
        self::assertSame(Quote::PAGES_REQUIRED, self::q($tariffs, $foreign, M::Abstract)->problem);
        self::assertSame(Quote::NO_TARIFF, self::q($tariffs, $own, M::Paper, 5)->problem, 'no tariff set for papers of own staff');
        self::assertSame(Quote::NO_TARIFF, self::q($tariffs, $own, M::Listener)->problem);
    }

    /** Teacher training seminar for listeners: free for teachers of the region, early price for others. */
    public function testPilot2TeacherTraining(): void
    {
        [$region, $other, $heads] = [11, 12, 13];
        $tariffs = [
            self::t($region, M::Listener, P::Flat, '0'),
            self::t($other, M::Listener, P::Flat, '200'),
            self::t($other, M::Listener, P::Flat, '150', 'UAH', ['to' => '2027-03-01T00:00:00+02:00']),
            self::t($heads, M::Listener, P::Flat, '300'),
        ];
        self::assertSame('0.00 UAH', self::amount(self::q($tariffs, $region, M::Listener)));
        self::assertSame('150.00 UAH', self::amount(self::q($tariffs, $other, M::Listener, null, '2027-02-28T21:59:59Z')), 'early price');
        self::assertSame('200.00 UAH', self::amount(self::q($tariffs, $other, M::Listener, null, '2027-02-28T22:00:00Z')), 'from 1 March, Kyiv time');
        self::assertSame('300.00 UAH', self::amount(self::q($tariffs, $heads, M::Listener)));
    }

    /** Research institute: fee per abstract with a cheaper poster, page bands for papers, EUR for foreign. */
    public function testPilot3ResearchInstitute(): void
    {
        [$researchers, $young, $foreign] = [21, 22, 23];
        $tariffs = [
            self::t($researchers, M::Abstract, P::Flat, '300'),
            self::t($researchers, M::Abstract, P::Flat, '150', 'UAH', ['presentation' => PresentationType::Poster]),
            self::t($researchers, M::Paper, P::Flat, '400', 'UAH', ['min' => 1, 'max' => 4]),
            self::t($researchers, M::Paper, P::PerPage, '100', 'UAH', ['min' => 5]),
            self::t($young, M::Abstract, P::Flat, '150'),
            self::t($foreign, M::Abstract, P::Flat, '20', 'EUR'),
        ];
        self::assertSame('300.00 UAH', self::amount(self::q($tariffs, $researchers, M::Abstract, null, '2027-02-01T10:00:00Z', PresentationType::Oral)));
        self::assertSame('150.00 UAH', self::amount(self::q($tariffs, $researchers, M::Abstract, null, '2027-02-01T10:00:00Z', PresentationType::Poster)));
        self::assertSame('400.00 UAH', self::amount(self::q($tariffs, $researchers, M::Paper, 4)));
        self::assertSame('700.00 UAH', self::amount(self::q($tariffs, $researchers, M::Paper, 7)));
        self::assertSame(Quote::PAGES_REQUIRED, self::q($tariffs, $researchers, M::Paper)->problem);
        self::assertSame('150.00 UAH', self::amount(self::q($tariffs, $young, M::Abstract)));
        self::assertSame('20.00 EUR', self::amount(self::q($tariffs, $foreign, M::Abstract)));
    }

    public function testTariffOutsideItsPeriodIsNotUsed(): void
    {
        $tariffs = [self::t(1, M::Listener, P::Flat, '100', 'UAH', ['from' => '2027-03-01T00:00:00Z'])];
        self::assertSame(Quote::NO_TARIFF, self::q($tariffs, 1, M::Listener)->problem);
        self::assertSame('100.00 UAH', self::amount(self::q($tariffs, 1, M::Listener, null, '2027-03-01T00:00:00Z')));
    }

    public function testMoneyParsingHasNoFloats(): void
    {
        self::assertSame(15050, Money::parse('150,5', 'UAH')?->minor);
        self::assertSame(150000, Money::parse('1 500', 'UAH')?->minor);
        self::assertSame(1, Money::parse('0.01', 'USD')?->minor);
        self::assertSame(500, Money::parse('500', 'JPY')?->minor);
        self::assertNull(Money::parse('1.234', 'UAH'), 'more decimals than the currency has');
        self::assertNull(Money::parse('-5', 'UAH'));
        self::assertNull(Money::parse('5', 'uah'));
        self::assertSame('1.005', (new Money(1005, 'KWD'))->decimal());
        self::assertSame('0.07', (new Money(7, 'EUR'))->decimal());
        self::assertSame(2000, Money::parse('20', 'EUR')?->times(1)->minor);
    }
}
