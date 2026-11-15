<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

/**
 * The number of an invoice: a sequence without gaps per context and year
 * (FEE-05), shown as "2027-00015".
 */
final class InvoiceNumber
{
    public static function format(int $year, int $sequence): string
    {
        return sprintf('%04d-%05d', $year, $sequence);
    }
}
