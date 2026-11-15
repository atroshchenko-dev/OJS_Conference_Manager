<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Puts a registered listener into the "Participant" user group of the
 * context (REG-01, REG-05). Implemented by Core\UserGroupGateway.
 */
interface ParticipantGroups
{
    public function assignParticipant(int $contextId, int $userId): void;
}
