<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Sends letters to participants (implemented in Core with the OJS mailer).
 */
interface ParticipantNotifier
{
    public function requestConfirmation(ContextInfo $context, ConfirmationRequest $request): void;
}
