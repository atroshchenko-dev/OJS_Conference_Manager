<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Institution;

interface InstitutionRepository
{
    public function findByContextId(int $contextId): ?InstitutionProfile;

    /**
     * Inserts or replaces the profile of its context, including signers and
     * payment accounts, in one transaction. Returns the profile with its id.
     */
    public function save(InstitutionProfile $profile): InstitutionProfile;
}
