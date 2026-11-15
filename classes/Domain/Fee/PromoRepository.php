<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Fee;

use DateTimeImmutable;

/** REG-11: promo codes of editions and the participations that used them. */
interface PromoRepository
{
    /** @return list<PromoCode> by code, with the uses counted */
    public function ofEdition(int $editionId): array;

    public function find(int $editionId, int $id): ?PromoCode;

    public function findByCode(int $editionId, string $code): ?PromoCode;

    public function save(PromoCode $code): PromoCode;

    public function delete(int $editionId, int $id): bool;

    /** Uses by any participation, cancelled ones included (a used code is kept for the record). */
    public function everUsed(int $id): bool;

    /**
     * Links the code to the participation, once per participation. False
     * when the limit of uses was reached meanwhile.
     */
    public function redeem(int $codeId, int $participationId, DateTimeImmutable $now): bool;

    public function ofParticipation(int $participationId): ?PromoCode;
}
