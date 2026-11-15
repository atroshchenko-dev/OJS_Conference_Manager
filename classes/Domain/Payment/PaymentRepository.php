<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment;

use DateTimeImmutable;

/**
 * Payment rules, invoices, their numbers and the payment journal.
 * Callers check that the edition belongs to the context first.
 */
interface PaymentRepository
{
    /** @return list<PaymentRule> */
    public function rules(int $editionId): array;

    public function findRule(int $editionId, int $id): ?PaymentRule;

    public function saveRule(PaymentRule $rule): PaymentRule;

    public function deleteRule(int $editionId, int $id): bool;

    /**
     * Next number without gaps for the context and year (FEE-05); runs in
     * the caller's transaction and locks the counter row.
     */
    public function nextNumber(int $contextId, int $year): int;

    /**
     * Saves a new invoice with its items, its number and payment reference
     * and the "issued" event, all in one transaction.
     */
    public function createInvoice(Invoice $invoice, ?int $actorId, DateTimeImmutable $now): Invoice;

    public function findInvoice(int $contextId, int $id): ?Invoice;

    /**
     * Changes the status and appends the event (the journal is never
     * updated or deleted, FEE-13).
     *
     * @param array<string, mixed> $changes columns: paid_minor, paid_at, waiver_reason, method
     * @param array<string, mixed> $payload
     */
    public function changeStatus(int $invoiceId, InvoiceStatus $to, array $changes, string $event, ?int $actorId, array $payload, DateTimeImmutable $now): void;

    /**
     * Current (not cancelled, not superseded) invoice that has an item for
     * the participation, or for the submission when given.
     */
    public function currentFor(int $participationId, ?int $submissionId = null): ?Invoice;

    /**
     * @param list<int> $participantIds
     * @return list<Invoice> invoices of the payer participants, newest first
     */
    public function ofParticipants(array $participantIds): array;

    /**
     * @return array{items: list<Invoice>, itemsMax: int}
     */
    public function ofEdition(int $contextId, int $editionId, ?InvoiceStatus $status, string $search, int $count, int $offset): array;

    /** @return list<array<string, mixed>> events of the invoice, oldest first */
    public function events(int $invoiceId): array;
}
