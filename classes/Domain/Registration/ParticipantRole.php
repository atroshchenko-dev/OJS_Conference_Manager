<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

enum ParticipantRole: string
{
    case Presenter = 'presenter';
    case Coauthor = 'coauthor';
    case Listener = 'listener';
    case Chair = 'chair';
    case Organizer = 'organizer';
    case Reviewer = 'reviewer';

    public function labelKey(): string
    {
        return 'plugins.generic.conferenceManager.registration.participantRole.' . $this->value;
    }
}
