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
     * @param array<string, mixed> $changes columns: paid_minor, paid_at, waiver_reason, method, payer_snapshot (JUR-05, an array)
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

    /**
     * Saves the receipt and appends "proof_uploaded" to the journal in one
     * transaction (FEE-09).
     */
    public function addProof(PaymentProof $proof, DateTimeImmutable $now): PaymentProof;

    /** The receipt, when its invoice belongs to the context. */
    public function findProof(int $contextId, int $id): ?PaymentProof;

    /**
     * @param list<int> $invoiceIds
     * @return array<int, list<PaymentProof>> receipts by invoice id, oldest first
     */
    public function proofsOf(array $invoiceIds): array;

    /**
     * Receipts of the context with their invoices, oldest first, for the
     * review queue (FEE-10).
     *
     * @return array{items: list<array{proof: PaymentProof, invoice: Invoice}>, itemsMax: int}
     */
    public function proofQueue(int $contextId, ?int $editionId, ?ProofStatus $status, int $count, int $offset): array;

    /**
     * Other receipts in the context with the same content.
     *
     * @return list<int> their invoice ids
     */
    public function sameFile(int $contextId, string $sha256, ?int $exceptProofId): array;

    /**
     * Closes a pending receipt and appends the event. False when the receipt
     * is no longer pending (somebody else reviewed it first).
     *
     * @param array<string, mixed> $payload
     */
    public function reviewProof(int $proofId, ProofStatus $to, ?int $reviewerId, ?string $note, string $event, array $payload, DateTimeImmutable $now): bool;

    /**
     * The payment journal of an edition (FEE-13), newest first, with the
     * invoice number of each event.
     *
     * @return array{items: list<array<string, mixed>>, itemsMax: int}
     */
    public function editionEvents(int $contextId, int $editionId, int $count, int $offset): array;

    /**
     * FEE-12: invoices of the context for the export in portions, oldest
     * first, with the payer's category and the role of the participation.
     * $dateField is "issued" or "paid" (fully paid on that day).
     *
     * @param callable(list<array{invoice: Invoice, categoryId: ?int, role: ?string}>): void $each
     */
    public function forExport(int $contextId, ?int $editionId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, string $dateField, bool $withReplaced, callable $each): void;
}
