<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Edition;

/**
 * Every method is bound to a context id: an edition of another context
 * is never found, changed or deleted (plan 7.9).
 */
interface EditionRepository
{
    public function find(int $contextId, int $id): ?Edition;

    public function findByPath(int $contextId, string $path): ?Edition;

    /** EDN-10: the edition linked to an OJS issue of the context. */
    public function findByIssueId(int $contextId, int $issueId): ?Edition;

    /**
     * @param list<EditionStatus> $statuses empty means all
     * @return list<Edition> newest first (by start date)
     */
    public function list(int $contextId, array $statuses = [], int $limit = 50, int $offset = 0): array;

    /**
     * @param list<EditionStatus> $statuses
     */
    public function count(int $contextId, array $statuses = []): int;

    public function save(Edition $edition): Edition;

    /**
     * Editions of ALL contexts with an open call whose abstract deadline
     * has passed (used by the scheduled task, EDN-03).
     *
     * @return list<Edition>
     */
    public function findOpenCallsPastDeadline(\DateTimeImmutable $now, int $limit = 500): array;

    /** True when participants, invoices or submissions point to the edition. */
    public function isInUse(int $contextId, int $id): bool;

    public function delete(int $contextId, int $id): bool;
}
