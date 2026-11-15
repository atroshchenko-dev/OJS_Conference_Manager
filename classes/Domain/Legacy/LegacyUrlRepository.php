<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Legacy;

/**
 * LEG-02: OCS papers of a journal and the articles they became. The old
 * path is kept as "conference/schedConf/paperId", unique on the site.
 */
interface LegacyUrlRepository
{
    /**
     * @return list<array{ocsPaperId: int, conference: string, schedConf: string, submissionId: ?int}>
     */
    public function all(int $contextId): array;

    /**
     * @return array{ocsPaperId: int, conference: string, schedConf: string, submissionId: ?int}|null
     */
    public function find(int $contextId, int $ocsPaperId): ?array;

    /**
     * Inserts or updates by old path; returns old paths that belong to
     * another journal and were left alone.
     *
     * @param list<array{ocsPaperId: int, conference: string, schedConf: string, submissionId: ?int}> $rows
     * @return list<string>
     */
    public function save(int $contextId, array $rows): array;

    public function clear(int $contextId): int;
}
