<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

/**
 * Review state of a payment receipt (FEE-10).
 */
enum ProofStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.proof.status.' . $this->value;
    }
}
