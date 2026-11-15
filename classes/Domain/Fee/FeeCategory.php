<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;

/**
 * A category of participants of an edition (FEE-01): own staff,
 * partners, students, foreign participants… Defined per edition.
 */
final class FeeCategory
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $editionId,
        public readonly string $code,
        public readonly LocalizedText $name,
        public readonly bool $requiresProof = false,
        public readonly int $sortOrder = 0,
    ) {
    }

    public function withId(int $id): self
    {
        return new self($id, $this->editionId, $this->code, $this->name, $this->requiresProof, $this->sortOrder);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'editionId' => $this->editionId,
            'code' => $this->code,
            'name' => $this->name->toArray(),
            'requiresProof' => $this->requiresProof,
            'sortOrder' => $this->sortOrder,
        ];
    }
}
