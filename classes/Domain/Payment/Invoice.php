<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use DateTimeImmutable;

/**
 * An invoice of one payer (FEE-04…07). Amounts are integers in minor
 * units. The payer and the recipient are copied at issue time, so the
 * document stays the same when the profile changes later.
 */
final class Invoice
{
    /**
     * @param list<InvoiceItem> $items
     * @param array<string, mixed> $payer snapshot: name, email, affiliation
     * @param array<string, mixed> $recipient snapshot: institution and account details
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $contextId,
        public readonly int $editionId,
        public readonly int $payerParticipantId,
        public readonly PaymentMode $mode,
        public readonly InvoiceStatus $status,
        public readonly Money $total,
        public readonly array $items,
        public readonly ?int $paymentAccountId = null,
        public readonly ?int $numberYear = null,
        public readonly ?string $number = null,
        public readonly ?string $paymentReference = null,
        public readonly Money|null $paid = null,
        public readonly ?string $waiverReason = null,
        public readonly ?int $supersedesId = null,
        public readonly ?DateTimeImmutable $dueAt = null,
        public readonly ?DateTimeImmutable $issuedAt = null,
        public readonly ?DateTimeImmutable $paidAt = null,
        public readonly array $payer = [],
        public readonly array $recipient = [],
        public readonly string $method = 'bank',
    ) {
    }

    public function paidAmount(): Money
    {
        return $this->paid ?? Money::zero($this->total->currency);
    }

    /** What is still to pay. */
    public function due(): Money
    {
        return new Money(max(0, $this->total->minor - $this->paidAmount()->minor), $this->total->currency);
    }

    public function isWaiver(): bool
    {
        return $this->method === 'waiver';
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $v = get_object_vars($this);
        foreach ($changes as $k => $value) {
            if (!array_key_exists($k, $v)) {
                throw new \InvalidArgumentException('Unknown invoice field ' . $k);
            }
            $v[$k] = $value;
        }
        return new self(...$v);
    }
}
