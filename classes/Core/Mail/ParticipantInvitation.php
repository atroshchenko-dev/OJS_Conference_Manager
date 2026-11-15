<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;

final class ParticipantInvitation extends ConferenceParticipantMailable
{
    protected static ?string $name = 'plugins.generic.conferenceManager.mailable.invitation.name';
    protected static ?string $description = 'plugins.generic.conferenceManager.mailable.invitation.description';
    protected static ?string $emailTemplateKey = ParticipantMailTexts::INVITATION;
}
