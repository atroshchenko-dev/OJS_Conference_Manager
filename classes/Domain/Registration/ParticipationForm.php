<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

enum ParticipationForm: string
{
    case InPerson = 'in_person';
    case Online = 'online';
    case Absentee = 'absentee';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.registration.participationForm.' . $this->value;
    }
}
