<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
use DateTimeImmutable;

/**
 * What a fee is calculated for: who (category), what (material, its
 * presentation type and pages) and when (the moment that decides early
 * prices: the submission or registration date).
 */
final class TariffQuery
{
    public function __construct(
        public readonly int $categoryId,
        public readonly MaterialType $material,
        public readonly DateTimeImmutable $at,
        public readonly ?int $pages = null,
        public readonly ?PresentationType $presentationType = null,
    ) {
    }
}
