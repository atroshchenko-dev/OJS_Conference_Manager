<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

/**
 * How participants of a category pay (PAY-01…05, PAY-09). The plugin decides no
 * legal questions: it only issues documents and shows instructions.
 */
enum PaymentMode: string
{
    case Free = 'free';
    case BankTransfer = 'bank_transfer';
    case TreasuryTransfer = 'treasury_transfer';
    case ExternalOrganizer = 'external_organizer';
    case ExternalLink = 'external_link';
    /** PAY-09: card payment through the journal's payment gateway (LiqPay…). */
    case Acquiring = 'acquiring';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.payment.mode.' . $this->value;
    }

    /** An invoice is issued and recorded (all but "free"). */
    public function issuesInvoice(): bool
    {
        return $this !== self::Free;
    }

    /** The invoice is a document with bank details (PDF). */
    public function hasDocument(): bool
    {
        return $this === self::BankTransfer || $this === self::TreasuryTransfer;
    }

    public function needsAccount(): bool
    {
        return $this->hasDocument();
    }

    public function needsUrl(): bool
    {
        return $this === self::ExternalLink;
    }

    /** The short method stored on the invoice (16 characters at most). */
    public function method(): string
    {
        return match ($this) {
            self::Free => 'none',
            self::BankTransfer => 'bank',
            self::TreasuryTransfer => 'treasury',
            self::ExternalOrganizer => 'organizer',
            self::ExternalLink => 'link',
            self::Acquiring => 'card',
        };
    }
}
