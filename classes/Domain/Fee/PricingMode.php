<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

enum PricingMode: string
{
    case Flat = 'flat';
    case PerPage = 'per_page';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.fee.pricing.' . $this->value;
    }
}
