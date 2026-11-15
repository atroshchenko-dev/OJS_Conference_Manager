<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;

/**
 * Tells the payer that an invoice was issued (FEE-04).
 */
interface InvoiceNotifier
{
    /**
     * @param array<string, mixed> $presented InvoiceService::present() of the invoice
     */
    public function invoiceIssued(ContextInfo $context, Invoice $invoice, array $presented): void;
}
