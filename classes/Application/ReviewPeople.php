<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/** Editors and authors of a submission with their affiliations (DEC-08, DEC-09). */
interface ReviewPeople
{
    /**
     * Managers and section editors assigned to the submission.
     *
     * @return list<array{userId: int, name: string, affiliations: list<string>}>
     */
    public function editors(int $submissionId, string $locale): array;

    /** @return array{userId: int, name: string, affiliations: list<string>}|null */
    public function user(int $userId, string $locale): ?array;

    /**
     * Authors of the current publication; affiliations in every language
     * and the ROR id when there is one.
     *
     * @return list<array{name: string, affiliations: list<string>}>
     */
    public function authors(int $submissionId, string $locale): array;

    /**
     * Active review forms of the journal.
     *
     * @return list<array{id: int, title: string}>
     */
    public function reviewForms(int $contextId, string $locale): array;

    /** The round number (1, 2, …) of an external review round, or null. */
    public function roundNumber(int $reviewRoundId): ?int;
}
