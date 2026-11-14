<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Unit\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionProfile;
use APP\plugins\generic\conferenceManager\classes\Domain\Institution\InstitutionRepository;

final class InMemoryInstitutionRepository implements InstitutionRepository
{
    /** @var array<int, InstitutionProfile> */
    public array $profiles = [];
    private int $nextId = 1;

    public function findByContextId(int $contextId): ?InstitutionProfile
    {
        return $this->profiles[$contextId] ?? null;
    }

    public function save(InstitutionProfile $profile): InstitutionProfile
    {
        $id = $profile->id ?? ($this->profiles[$profile->contextId]->id ?? $this->nextId++);
        return $this->profiles[$profile->contextId] = $profile->withId($id);
    }
}
