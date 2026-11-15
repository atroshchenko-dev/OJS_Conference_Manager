<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use DateTimeImmutable;

/** Counts for the dashboard of an edition (RPT-01) and consent versions (DAT-01). */
interface EditionStatistics
{
    /** @return array<string, array<string, int>> role => status => count of participations */
    public function participations(int $editionId): array;

    /** @return list<array{status: string, method: string, currency: string, count: int, totalMinor: int, paidMinor: int, withPayment: int}> */
    public function invoices(int $editionId): array;

    public function pendingProofs(int $editionId): int;

    /** Keeps the text of a consent version the first time it is used. */
    public function rememberConsent(int $contextId, string $version, string $texts, DateTimeImmutable $now): void;

    /** @return list<array{version: string, createdAt: ?string, texts: string, agreements: int}> newest first */
    public function consentVersions(int $contextId): array;
}
