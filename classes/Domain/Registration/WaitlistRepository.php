<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

use DateTimeImmutable;

/** REG-12: in-person listener seats of an edition and the waiting list. */
interface WaitlistRepository
{
    public function seats(int $editionId): ?int;

    public function setSeats(int $editionId, ?int $seats): void;

    /** Active in-person listeners, the ones that take seats. */
    public function taken(int $editionId): int;

    /**
     * The waiting list in order of registration.
     *
     * @return list<array{participationId: int, participantId: int, email: string, givenName: string, familyName: string, waitlistedAt: string}>
     */
    public function queue(int $editionId, string $locale): array;

    /**
     * Gives a seat to the first in the list when one is free; the edition
     * row is locked meanwhile. Returns the participation id or null.
     */
    public function promoteNext(int $editionId, DateTimeImmutable $now): ?int;
}
