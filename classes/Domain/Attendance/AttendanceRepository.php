<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Attendance;

use DateTimeImmutable;

interface AttendanceRepository
{
    /**
     * Participations of an edition with what attendance and certificates need.
     *
     * @return list<array{participationId: int, participantId: int, userId: ?int, email: string, name: string, affiliation: string,
     *     role: string, status: string, form: string, submissionId: ?int, attended: bool, minutes: ?int, source: ?string}>
     */
    public function ofEdition(int $contextId, int $editionId, string $locale): array;

    /**
     * @param list<int> $participationIds of this edition
     * @return int rows changed
     */
    public function mark(int $editionId, array $participationIds, bool $attended, ?int $minutes, string $source, ?int $actorId, DateTimeImmutable $now): int;
}
