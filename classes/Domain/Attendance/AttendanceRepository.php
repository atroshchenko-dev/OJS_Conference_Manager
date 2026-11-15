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

    /**
     * CRT-11: adds a person who served the edition (reviewer, organiser)
     * without a submission. The person is confirmed: the journal adds them
     * from its own records. An existing active participation in the same
     * role is kept.
     *
     * @param array{email: string, userId: ?int, givenName: string, familyName: string, affiliation: string, locale: string} $person
     * @return array{participationId: int, created: bool}
     */
    public function addStaff(int $contextId, int $editionId, string $role, array $person, DateTimeImmutable $now): array;

    /**
     * Presence at the sessions of the program (ATT-01 per session).
     *
     * @return array<int, list<int>> participation id => ids of the sessions where the person was present
     */
    public function sessionPresence(int $editionId): array;

    /**
     * @return array<int, array{minutes: ?int, source: string, markedAt: string}> participation id => mark at one session
     */
    public function ofSession(int $sessionId): array;

    /**
     * Marks or unmarks presence at a session of the edition. A mark from
     * an import never replaces or removes a mark made by hand.
     *
     * @param list<int> $participationIds of this edition
     * @return int rows changed
     */
    public function markSession(int $editionId, int $sessionId, array $participationIds, bool $attended, ?int $minutes, string $source, ?int $actorId, DateTimeImmutable $now): int;
}
