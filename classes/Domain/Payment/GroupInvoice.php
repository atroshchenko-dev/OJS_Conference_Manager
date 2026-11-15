<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;

/**
 * REG-09: one organisation pays for several participants with one invoice.
 * Each participant keeps an item of their own, drafted by the same rules
 * as a single invoice; the drafts must go to the same account in the same
 * currency, or the accountant could not take one payment for them.
 */
final class GroupInvoice
{
    public const MIN = 2;
    public const MAX = 200;

    public const CURRENCY = 'currency';
    public const ACCOUNT = 'account';

    /**
     * @param list<array{invoice: Invoice, name: string}> $drafts single invoices of the members, with their names
     * @param array<string, string> $payer snapshot of the organisation
     * @return array{0: ?Invoice, 1: ?string} the invoice, or the reason why the drafts do not fit together
     */
    public static function combine(array $drafts, int $payerParticipantId, array $payer): array
    {
        if ($drafts === []) {
            return [null, null];
        }
        $first = $drafts[0]['invoice'];
        $currency = $first->total->currency;
        $items = [];
        $total = Money::zero($currency);
        foreach ($drafts as ['invoice' => $draft, 'name' => $name]) {
            if ($draft->total->currency !== $currency) {
                return [null, self::CURRENCY];
            }
            if ($draft->paymentAccountId !== $first->paymentAccountId || $draft->mode !== $first->mode) {
                return [null, self::ACCOUNT];
            }
            foreach ($draft->items as $item) {
                $items[] = new InvoiceItem(
                    trim($name) === '' ? $item->description : $item->description . ' — ' . trim($name),
                    $item->quantity,
                    $item->unit,
                    $item->amount,
                    $item->participationId,
                    $item->submissionId,
                    $item->tariffId,
                );
            }
            $total = $total->plus($draft->total);
        }
        $dueAt = null;
        foreach ($drafts as ['invoice' => $draft]) {
            if ($draft->dueAt !== null && ($dueAt === null || $draft->dueAt < $dueAt)) {
                $dueAt = $draft->dueAt;
            }
        }
        return [$first->with([
            'payerParticipantId' => $payerParticipantId,
            'total' => $total,
            'items' => $items,
            'payer' => $payer,
            'dueAt' => $dueAt,
            'supersedesId' => null,
            'paid' => null,
        ]), null];
    }

    /** More than one item: the invoice of a group, not reissued item by item. */
    public static function isGroup(Invoice $invoice): bool
    {
        return count($invoice->items) > 1;
    }
}
