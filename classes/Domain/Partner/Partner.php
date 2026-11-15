<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Partner;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;

/**
 * INS-04: a co-organiser or partner of one edition (another institution, an
 * NGO, a sponsor) with a logo. A co-organiser may receive the fees: then it
 * points to one of the journal's payment accounts of the "partner" kind.
 */
final class Partner
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $editionId,
        public readonly PartnerRole $role,
        public readonly LocalizedText $name,
        public readonly string $url = '',
        public readonly ?string $logoPath = null,
        public readonly ?int $paymentAccountId = null,
        public readonly int $seq = 0,
    ) {
    }

    public function withId(int $id): self
    {
        return new self($id, $this->editionId, $this->role, $this->name, $this->url, $this->logoPath, $this->paymentAccountId, $this->seq);
    }

    public function withSeq(int $seq): self
    {
        return new self($this->id, $this->editionId, $this->role, $this->name, $this->url, $this->logoPath, $this->paymentAccountId, $seq);
    }

    public function withLogo(?string $logoPath): self
    {
        return new self($this->id, $this->editionId, $this->role, $this->name, $this->url, $logoPath, $this->paymentAccountId, $this->seq);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'editionId' => $this->editionId,
            'role' => $this->role->value,
            'name' => $this->name->toArray(),
            'url' => $this->url,
            'logoPath' => $this->logoPath,
            'paymentAccountId' => $this->paymentAccountId,
            'seq' => $this->seq,
        ];
    }
}
