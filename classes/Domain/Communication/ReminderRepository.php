<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Communication;

use DateTimeImmutable;

/**
 * What the reminders (COM-03) are about, and which were already sent.
 * A reminder is claimed before it is sent, so two runs at the same time
 * never send it twice.
 */
interface ReminderRepository
{
    /**
     * Open invoices of the edition with something still to pay and no
     * receipt waiting for review.
     *
     * @return list<array{invoiceId: int, email: string, name: string, number: string, dueMinor: int, currency: string, dueAt: ?DateTimeImmutable, reference: string, participantId: int}>
     */
    public function unpaidInvoices(int $editionId, string $locale): array;

    /**
     * Active participations of the edition, one row per participation.
     *
     * @return list<array{participantId: int, email: string, name: string, role: string, submissionId: ?int}>
     */
    public function participations(int $editionId, string $locale): array;

    public function claim(int $contextId, int $editionId, string $kind, int $targetId, string $email, DateTimeImmutable $now): bool;

    /** Frees a claim whose letter could not be sent, so the next run tries again. */
    public function release(string $kind, int $targetId): void;

    /** @return array<string, int> kind => count */
    public function sentCounts(int $editionId): array;
}
