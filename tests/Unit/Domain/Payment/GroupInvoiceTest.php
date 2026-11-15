<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\GroupInvoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceItem;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * REG-09: drafts of single invoices combined into one.
 */
final class GroupInvoiceTest extends TestCase
{
    private static function draft(int $participationId, int $minor, string $currency = 'UAH', ?int $account = 1, PaymentMode $mode = PaymentMode::BankTransfer, ?string $due = null): Invoice
    {
        $m = new Money($minor, $currency);
        return new Invoice(
            null,
            1,
            7,
            $participationId,
            $mode,
            InvoiceStatus::Issued,
            $m,
            [new InvoiceItem('Оргвнесок', 1, $m, $m, $participationId)],
            $account,
            dueAt: $due === null ? null : new DateTimeImmutable($due),
        );
    }

    public function testItemsTotalPayerAndEarliestDeadline(): void
    {
        [$group, $problem] = GroupInvoice::combine([
            ['invoice' => self::draft(11, 15000, due: '2027-05-01'), 'name' => 'Олена Коваль'],
            ['invoice' => self::draft(12, 10000, due: '2027-04-01'), 'name' => ' '],
        ], 99, ['name' => 'Ліцей № 5']);
        self::assertNull($problem);
        self::assertSame(25000, $group?->total->minor);
        self::assertSame(99, $group?->payerParticipantId);
        self::assertSame(['Оргвнесок — Олена Коваль', 'Оргвнесок'], array_map(static fn (InvoiceItem $i): string => $i->description, $group?->items ?? []));
        self::assertSame('2027-04-01', $group?->dueAt?->format('Y-m-d'));
        self::assertSame('Ліцей № 5', $group?->payer['name']);
        self::assertTrue(GroupInvoice::isGroup($group ?? throw new \LogicException()));
        self::assertFalse(GroupInvoice::isGroup(self::draft(1, 1)));
    }

    public function testDraftsThatDoNotFit(): void
    {
        self::assertSame(GroupInvoice::CURRENCY, GroupInvoice::combine([
            ['invoice' => self::draft(1, 100), 'name' => ''], ['invoice' => self::draft(2, 100, 'EUR'), 'name' => ''],
        ], 1, [])[1]);
        self::assertSame(GroupInvoice::ACCOUNT, GroupInvoice::combine([
            ['invoice' => self::draft(1, 100), 'name' => ''], ['invoice' => self::draft(2, 100, account: 2), 'name' => ''],
        ], 1, [])[1]);
        self::assertSame(GroupInvoice::ACCOUNT, GroupInvoice::combine([
            ['invoice' => self::draft(1, 100), 'name' => ''], ['invoice' => self::draft(2, 100, mode: PaymentMode::TreasuryTransfer), 'name' => ''],
        ], 1, [])[1]);
        self::assertSame([null, null], GroupInvoice::combine([], 1, []));
    }
}
