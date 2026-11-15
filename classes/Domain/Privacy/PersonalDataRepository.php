<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Privacy;

use DateTimeImmutable;

/**
 * Personal data kept by the plugin (DAT-02, DAT-03, DAT-04, DAT-06).
 */
interface PersonalDataRepository
{
    /**
     * Participant records of a person in a journal: by account or by email.
     *
     * @return list<int>
     */
    public function participantsOf(int $contextId, ?int $userId, string $email): array;

    /**
     * Everything the plugin keeps about these participant records, table by
     * table, as stored (DAT-03).
     *
     * @param list<int> $participantIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function export(array $participantIds): array;

    /**
     * DAT-04: removes the person from the participant record and from
     * mailing logs. Invoices and certificates keep their snapshots: they are
     * accounting records and the public register.
     *
     * @return bool false when the record is unknown or already anonymised
     */
    public function anonymize(int $contextId, int $participantId, string $placeholderName, DateTimeImmutable $now): bool;

    /**
     * DAT-06: the plugin's references to the old OJS account move to the new one.
     *
     * @return array<string, int> "table.column" => rows changed
     */
    public function mergeUsers(int $oldUserId, int $newUserId): array;

    /**
     * DAT-02: receipt scans of the journal uploaded before the date, already
     * reviewed and not yet removed.
     *
     * @return list<array{id: int, key: string}>
     */
    public function expiredProofs(int $contextId, DateTimeImmutable $uploadedBefore, int $limit): array;

    public function markProofPurged(int $proofId, DateTimeImmutable $now): void;

    /**
     * DAT-02: participant records of editions that ended before the date,
     * not anonymised yet.
     *
     * @return list<int>
     */
    public function participantsOfEndedEditions(int $contextId, DateTimeImmutable $endedBefore, int $limit): array;

    /**
     * Rows of the register for the participant: whether it is anonymised.
     */
    public function isAnonymized(int $participantId): bool;
}
