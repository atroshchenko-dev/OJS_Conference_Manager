<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Mail;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;

final class ProofAcceptedNotify extends ConferenceParticipantMailable
{
    protected static ?string $name = 'plugins.generic.conferenceManager.mailable.proofAccepted.name';
    protected static ?string $description = 'plugins.generic.conferenceManager.mailable.proofAccepted.description';
    protected static ?string $emailTemplateKey = ParticipantMailTexts::PROOF_ACCEPTED;
}
