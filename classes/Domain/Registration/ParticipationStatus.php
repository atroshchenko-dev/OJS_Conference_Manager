<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

enum ParticipationStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.participation.status.' . $this->value;
    }
}
