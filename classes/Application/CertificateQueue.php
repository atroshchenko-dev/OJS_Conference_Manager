<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/** CRT-06: issues a portion of certificates in the background. */
interface CertificateQueue
{
    /**
     * @param list<int> $participationIds this portion
     * @param list<int> $rest the next portions, queued by the job after this one
     */
    public function push(int $contextId, int $editionId, array $participationIds, ?int $actorId, string $locale, array $rest = []): void;
}
