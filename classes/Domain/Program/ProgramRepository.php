<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Program;

/** PRG-02, PRG-03: rooms, sessions and the place of each paper. */
interface ProgramRepository
{
    /** @return list<Room> */
    public function rooms(int $editionId): array;

    public function saveRoom(Room $room): Room;

    public function deleteRoom(int $editionId, int $id): bool;

    /** @return list<ProgramSession> by start time, with chairs and items */
    public function sessions(int $editionId): array;

    public function findSession(int $editionId, int $id): ?ProgramSession;

    /** Saves the session and its chairs; items are kept. */
    public function saveSession(ProgramSession $session): ProgramSession;

    public function deleteSession(int $editionId, int $id): bool;

    /**
     * Puts the paper at a position of a session (0 = first), taking it
     * out of any other session of the edition; null session takes it out.
     */
    public function placeItem(int $editionId, int $submissionId, ?int $sessionId, int $position, ?int $durationMin): void;

    /**
     * Presenters of papers: submission id => participant ids.
     *
     * @param list<int> $submissionIds
     * @return array<int, list<int>>
     */
    public function presenters(array $submissionIds): array;

    /** @return list<array{id: int, name: string}> participants of the edition, by name */
    public function people(int $editionId, string $locale): array;

    /**
     * ATT-01: sessions that these participants chair.
     *
     * @param list<int> $participantIds
     * @return array<int, int> session id => edition id
     */
    public function chairedBy(array $participantIds): array;
}
