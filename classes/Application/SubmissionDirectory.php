<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Registration\AuthorInfo;

/**
 * Access to OJS submissions, authors and users (implemented in Core).
 */
interface SubmissionDirectory
{
    public function find(int $submissionId): ?SubmissionInfo;

    /** @return list<AuthorInfo> authors of the current publication */
    public function authors(int $submissionId): array;

    /** Writes conferenceEditionId and presentationType to the current publication. */
    public function linkToEdition(int $submissionId, int $editionId, ?string $presentationType): void;

    public function userEmail(int $userId): ?string;

    public function userIdByEmail(string $email): ?int;
}
