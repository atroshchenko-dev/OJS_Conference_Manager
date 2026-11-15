<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

/**
 * Register filter by payment (REG-07 with FEE-04…10): by the current
 * invoice of the participation.
 */
enum PaymentFilter: string
{
    /** Paid or exempt. */
    case Paid = 'paid';
    /** An open invoice. */
    case Unpaid = 'unpaid';
    /** An open invoice with a receipt waiting for review. */
    case ProofPending = 'proof_pending';
    /** No current invoice. */
    case None = 'none';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.participants.payment.' . $this->value;
    }
}
