<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

enum ReviewMode: string
{
    case Light = 'light';
    case TwoPhase = 'two_phase';
    case Standard = 'standard';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.edition.reviewMode.' . $this->value;
    }
}
