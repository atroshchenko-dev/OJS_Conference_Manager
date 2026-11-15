<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;

final class ReviewBidAssigned extends ConferenceParticipantMailable
{
    protected static ?string $name = 'plugins.generic.conferenceManager.mailable.bidAssigned.name';
    protected static ?string $description = 'plugins.generic.conferenceManager.mailable.bidAssigned.description';
    protected static ?string $emailTemplateKey = ParticipantMailTexts::BID_ASSIGNED;
}
