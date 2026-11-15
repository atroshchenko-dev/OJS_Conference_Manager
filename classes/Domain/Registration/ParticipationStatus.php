<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

enum ParticipationStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    /** REG-12: an in-person listener waiting for a seat. */
    case Waitlisted = 'waitlisted';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.participation.status.' . $this->value;
    }
}
