<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;

/**
 * Tells the payer how the receipt was reviewed (FEE-10).
 */
interface ProofNotifier
{
    /**
     * @param array<string, mixed> $presented InvoiceService::present() of the invoice after the review
     */
    public function proofReviewed(ContextInfo $context, Invoice $invoice, array $presented, bool $accepted, ?string $note): void;
}
