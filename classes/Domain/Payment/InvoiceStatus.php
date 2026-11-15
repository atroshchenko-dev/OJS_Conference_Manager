<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Waived = 'waived';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
    case RefundRequested = 'refund_requested';
    case Refunded = 'refunded';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.invoice.status.' . $this->value;
    }

    /** Still waits for money. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Issued, self::Overdue], true);
    }

    /** Counts as the current invoice of a participation. */
    public function isCurrent(): bool
    {
        return !in_array($this, [self::Cancelled, self::Superseded], true);
    }
}
