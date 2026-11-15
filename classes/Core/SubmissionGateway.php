<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionDirectory;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionInfo;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\AuthorInfo;
use PKP\author\Author;

/**
 * Adapter to OJS submissions, authors and users.
 */
final class SubmissionGateway implements SubmissionDirectory
{
    public function find(int $submissionId): ?SubmissionInfo
    {
        $submission = Repo::submission()->get($submissionId);
        if ($submission === null) {
            return null;
        }
        $publication = $submission->getCurrentPublication();
        $title = $publication === null ? [] : array_filter((array) $publication->getData('title'), 'is_string');
        return new SubmissionInfo(
            (int) $submission->getId(),
            (int) $submission->getData('contextId'),
            (string) $submission->getData('submissionProgress') === '' && $submission->getData('dateSubmitted') !== null,
            array_map(static fn (string $t): string => trim(strip_tags($t)), $title),
            (string) $submission->getData('locale'),
        );
    }

    public function authors(int $submissionId): array
    {
        $publication = Repo::submission()->get($submissionId)?->getCurrentPublication();
        if ($publication === null) {
            return [];
        }
        $out = [];
        foreach ($publication->getData('authors') ?? [] as $author) {
            if (!$author instanceof Author || trim((string) $author->getEmail()) === '') {
                continue;
            }
            $email = (string) $author->getEmail();
            $affiliations = $author->getLocalizedAffiliationNamesAsString();
            $out[] = new AuthorInfo(
                (int) $author->getId(),
                $email,
                array_filter((array) $author->getData('givenName'), 'is_string'),
                array_filter((array) $author->getData('familyName'), 'is_string'),
                $affiliations === '' ? null : $affiliations,
                $author->getOrcid() ? (string) $author->getOrcid() : null,
                $author->getCountry() ? (string) $author->getCountry() : null,
                $this->userIdByEmail($email),
            );
        }
        return $out;
    }

    public function linkToEdition(int $submissionId, int $editionId, ?string $presentationType): void
    {
        $publication = Repo::submission()->get($submissionId)?->getCurrentPublication();
        if ($publication === null) {
            return;
        }
        Repo::publication()->edit($publication, [
            'conferenceEditionId' => $editionId,
            'presentationType' => $presentationType,
        ]);
    }

    public function userEmail(int $userId): ?string
    {
        $email = Repo::user()->get($userId, true)?->getEmail();
        return $email === null || $email === '' ? null : (string) $email;
    }

    public function userIdByEmail(string $email): ?int
    {
        $id = Repo::user()->getByEmail(trim($email), true)?->getId();
        return $id === null ? null : (int) $id;
    }
}
