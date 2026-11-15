<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use DateTimeImmutable;

/**
 * A receipt or payment order uploaded by the payer (FEE-09). The file is
 * kept in private storage; only its path inside that storage is saved.
 */
final class PaymentProof
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $invoiceId,
        public readonly ?int $uploadedBy,
        public readonly string $filePath,
        public readonly string $originalName,
        public readonly string $mime,
        public readonly int $size,
        public readonly string $sha256,
        public readonly ProofStatus $status = ProofStatus::Pending,
        public readonly ?int $reviewedBy = null,
        public readonly ?DateTimeImmutable $reviewedAt = null,
        public readonly ?string $note = null,
        public readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === ProofStatus::Pending;
    }
}
