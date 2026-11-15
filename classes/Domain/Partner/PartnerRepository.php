<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Partner;

interface PartnerRepository
{
    /** @return list<Partner> in the order of the edition */
    public function ofEdition(int $editionId): array;

    public function find(int $editionId, int $id): ?Partner;

    public function save(Partner $partner): Partner;

    public function delete(int $editionId, int $id): bool;
}
