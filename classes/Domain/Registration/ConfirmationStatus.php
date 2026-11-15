<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

enum ConfirmationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Declined = 'declined';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.registration.confirmationStatus.' . $this->value;
    }
}
