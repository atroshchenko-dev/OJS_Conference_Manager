<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use DateTimeImmutable;

/**
 * One cell of the tariff matrix (FEE-02): category × material ×
 * (optionally) presentation type × page range × period of validity.
 * A zero amount means "free for this category".
 */
final class Tariff
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $categoryId,
        public readonly MaterialType $material,
        public readonly PricingMode $pricing,
        public readonly Money $amount,
        public readonly ?PresentationType $presentationType = null,
        public readonly ?int $minPages = null,
        public readonly ?int $maxPages = null,
        public readonly ?DateTimeImmutable $validFrom = null,
        public readonly ?DateTimeImmutable $validTo = null,
    ) {
    }

    public function withId(int $id): self
    {
        return new self($id, $this->categoryId, $this->material, $this->pricing, $this->amount, $this->presentationType, $this->minPages, $this->maxPages, $this->validFrom, $this->validTo);
    }

    public function hasPageRange(): bool
    {
        return $this->minPages !== null || $this->maxPages !== null;
    }

    public function isValidAt(DateTimeImmutable $at): bool
    {
        return ($this->validFrom === null || $at >= $this->validFrom)
            && ($this->validTo === null || $at < $this->validTo);
    }

    public function coversPages(?int $pages): bool
    {
        if (!$this->hasPageRange()) {
            return true;
        }
        return $pages !== null
            && ($this->minPages === null || $pages >= $this->minPages)
            && ($this->maxPages === null || $pages <= $this->maxPages);
    }
}
