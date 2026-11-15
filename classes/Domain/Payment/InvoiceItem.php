<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;

final class InvoiceItem
{
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly Money $unit,
        public readonly Money $amount,
        public readonly ?int $participationId = null,
        public readonly ?int $submissionId = null,
        public readonly ?int $tariffId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit->toArray(),
            'amount' => $this->amount->toArray(),
            'participationId' => $this->participationId,
            'submissionId' => $this->submissionId,
            'tariffId' => $this->tariffId,
        ];
    }
}
