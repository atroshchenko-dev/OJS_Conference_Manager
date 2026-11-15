<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentProof;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofFile;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * Receipts and their review (FEE-09, FEE-10).
 *
 * The payer uploads a receipt or a payment order for an open invoice. The
 * registration manager sees the queue of pending receipts and accepts one
 * (the invoice is marked paid) or rejects it with a note. The payer gets a
 * letter either way. Every step is appended to the payment journal.
 */
final class ProofService
{
    private const K = 'plugins.generic.conferenceManager.proof.error.';

    /** Pending receipts one invoice may have at a time. */
    public const MAX_PENDING = 5;

    /** Receipts one invoice may have in all. */
    public const MAX_TOTAL = 20;

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InvoiceService $invoices,
        private readonly ProofStore $store,
        private readonly ?ProofNotifier $notifier = null,
        private readonly ?AuditLogger $audit = null,
        /** @var (\Closure(string, array<string, mixed>, string): string)|null */
        private readonly ?\Closure $translate = null,
    ) {
    }

    /**
     * The payer uploads a receipt. $warnings lists "duplicate" when the
     * same file was already sent for another invoice in the journal.
     *
     * @return array{0: ?array<string, mixed>, 1: ValidationErrors, 2: list<string>}|null null: no such invoice for this person
     */
    public function upload(ContextInfo $context, int $invoiceId, ?int $userId, string $email, string $tmpPath, string $originalName, DateTimeImmutable $now, string $locale): ?array
    {
        $invoice = $this->invoices->find($context, $invoiceId);
        if ($invoice === null || !$this->invoices->isPayer($invoice, $userId, $email)) {
            return null;
        }
        $errors = new ValidationErrors();
        if (!$invoice->status->isOpen() || $invoice->isWaiver()) {
            $errors->add('status', 'plugins.generic.conferenceManager.payment.error.notOpen');
            return [null, $errors, []];
        }
        $size = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
        if ($size <= 0) {
            $errors->add('file', self::K . 'empty');
            return [null, $errors, []];
        }
        if ($size > ProofFile::MAX_BYTES) {
            $errors->add('file', self::K . 'tooLarge');
            return [null, $errors, []];
        }
        $head = (string) file_get_contents($tmpPath, false, null, 0, 16);
        $mime = ProofFile::detectMime($head);
        if ($mime === null) {
            $errors->add('file', self::K . 'type');
            return [null, $errors, []];
        }
        $sha = (string) hash_file('sha256', $tmpPath);
        $existing = $this->payments->proofsOf([$invoiceId])[$invoiceId] ?? [];
        $pending = count(array_filter($existing, static fn (PaymentProof $p): bool => $p->isPending()));
        if ($pending >= self::MAX_PENDING || count($existing) >= self::MAX_TOTAL) {
            $errors->add('file', self::K . 'tooMany');
            return [null, $errors, []];
        }
        foreach ($existing as $p) {
            if ($p->sha256 === $sha && $p->status !== ProofStatus::Rejected) {
                $errors->add('file', self::K . 'sameInvoice');
                return [null, $errors, []];
            }
        }
        $key = sprintf('%d/%d/%s-%s.%s', $context->id, $invoiceId, substr($sha, 0, 16), bin2hex(random_bytes(4)), ProofFile::extension($mime));
        if (!$this->store->put($tmpPath, $key)) {
            $errors->add('file', self::K . 'storage');
            return [null, $errors, []];
        }
        $name = ProofFile::displayName($originalName, $mime);
        try {
            $proof = $this->payments->addProof(new PaymentProof(null, $invoiceId, $userId, $key, $name, $mime, $size, $sha), $now);
        } catch (\Throwable $e) {
            $this->store->remove($key);
            throw $e;
        }
        $warnings = $this->payments->sameFile($context->id, $sha, $proof->id) === [] ? [] : ['duplicate'];
        $this->audit?->log($context->id, $userId, 'invoice', $invoiceId, 'proof_uploaded', null, ['proofId' => $proof->id, 'size' => $size, 'duplicate' => $warnings !== []]);
        return [$this->presentProof($context, $proof, $locale), $errors, $warnings];
    }

    /**
     * The review queue (FEE-10): pending receipts first come first served.
     *
     * @return array{items: list<array<string, mixed>>, itemsMax: int}
     */
    public function queue(ContextInfo $context, ?int $editionId, ?ProofStatus $status, int $count, int $offset, string $locale): array
    {
        $page = $this->payments->proofQueue($context->id, $editionId, $status, $count, $offset);
        return [
            'items' => array_map(fn (array $row): array => $this->presentForReview($context, $row['proof'], $row['invoice'], $locale), $page['items']),
            'itemsMax' => $page['itemsMax'],
        ];
    }

    /** @return array<string, mixed>|null one receipt as the queue shows it */
    public function get(ContextInfo $context, int $proofId, string $locale): ?array
    {
        $proof = $this->payments->findProof($context->id, $proofId);
        $invoice = $proof === null ? null : $this->invoices->find($context, $proof->invoiceId);
        return $proof === null || $invoice === null ? null : $this->presentForReview($context, $proof, $invoice, $locale);
    }

    /** @return array<string, mixed> */
    private function presentForReview(ContextInfo $context, PaymentProof $proof, Invoice $invoice, string $locale): array
    {
        $presented = $this->invoices->present($context, $invoice, $locale);
        return $this->presentProof($context, $proof, $locale) + [
            'duplicateOf' => $this->payments->sameFile($context->id, $proof->sha256, $proof->id),
            'invoice' => array_intersect_key($presented, array_flip(['id', 'editionId', 'editionName', 'number', 'paymentReference', 'status', 'statusLabel', 'total', 'paid', 'due', 'payer', 'isOpen'])),
        ];
    }

    /**
     * Accepts the receipt: the invoice is marked paid for what is due, or
     * for $amount (a part payment).
     *
     * @return ValidationErrors|null null: no such receipt
     */
    public function accept(ContextInfo $context, int $proofId, ?string $amount, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $proof = $this->payments->findProof($context->id, $proofId);
        if ($proof === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $invoice = $this->invoices->find($context, $proof->invoiceId);
        if (!$proof->isPending() || $invoice === null || !$invoice->status->isOpen()) {
            $errors->add('status', self::K . 'notPending');
            return $errors;
        }
        if ($amount !== null && trim($amount) !== '') {
            $parsed = Money::parse($amount, $invoice->total->currency);
            if ($parsed === null || $parsed->minor <= 0) {
                $errors->add('amount', 'plugins.generic.conferenceManager.fee.error.amount');
                return $errors;
            }
        }
        if (!$this->payments->reviewProof($proofId, ProofStatus::Accepted, $actorId, null, 'proof_accepted', [], $now)) {
            $errors->add('status', self::K . 'notPending');
            return $errors;
        }
        $paid = $this->invoices->markPaid($context, $proof->invoiceId, $amount, $this->t('plugins.generic.conferenceManager.proof.paidNote', ['id' => (string) $proofId], $context->primaryLocale), $actorId, $now);
        if ($paid !== null && !$paid->isEmpty()) {
            return $paid;
        }
        $this->audit?->log($context->id, $actorId, 'invoice', $proof->invoiceId, 'proof_accepted', null, ['proofId' => $proofId]);
        $this->notify($context, $proof->invoiceId, true, null);
        return $errors;
    }

    /** @return ValidationErrors|null null: no such receipt */
    public function reject(ContextInfo $context, int $proofId, string $note, ?int $actorId, DateTimeImmutable $now): ?ValidationErrors
    {
        $proof = $this->payments->findProof($context->id, $proofId);
        if ($proof === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $note = mb_substr(trim($note), 0, 1000);
        if ($note === '') {
            $errors->add('note', self::K . 'noteRequired');
            return $errors;
        }
        if (!$proof->isPending() || !$this->payments->reviewProof($proofId, ProofStatus::Rejected, $actorId, $note, 'proof_rejected', ['note' => $note], $now)) {
            $errors->add('status', self::K . 'notPending');
            return $errors;
        }
        $this->audit?->log($context->id, $actorId, 'invoice', $proof->invoiceId, 'proof_rejected', null, ['proofId' => $proofId]);
        $this->notify($context, $proof->invoiceId, false, $note);
        return $errors;
    }

    /**
     * The file for a manager, or for the payer when $payer = [userId, email].
     *
     * @param array{0: ?int, 1: string}|null $payer
     * @return array{content: string, mime: string, name: string}|null
     */
    public function file(ContextInfo $context, int $proofId, ?array $payer): ?array
    {
        $proof = $this->payments->findProof($context->id, $proofId);
        if ($proof === null) {
            return null;
        }
        if ($payer !== null) {
            $invoice = $this->invoices->find($context, $proof->invoiceId);
            if ($invoice === null || !$this->invoices->isPayer($invoice, $payer[0], $payer[1])) {
                return null;
            }
        }
        $content = $this->store->read($proof->filePath);
        return $content === null ? null : ['content' => $content, 'mime' => $proof->mime, 'name' => $proof->originalName];
    }

    /**
     * Receipts of the invoices, for the cabinet and the invoice card.
     *
     * @param list<int> $invoiceIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function ofInvoices(ContextInfo $context, array $invoiceIds, string $locale): array
    {
        $out = [];
        foreach ($this->payments->proofsOf($invoiceIds) as $invoiceId => $proofs) {
            $out[$invoiceId] = array_map(fn (PaymentProof $p): array => $this->presentProof($context, $p, $locale), $proofs);
        }
        return $out;
    }

    /**
     * Adds "proofs" and "canUploadProof" to presented invoices.
     *
     * @param list<array<string, mixed>> $presented
     * @return list<array<string, mixed>>
     */
    public function withProofs(ContextInfo $context, array $presented, string $locale): array
    {
        $proofs = $this->ofInvoices($context, array_map(static fn (array $i): int => (int) $i['id'], $presented), $locale);
        return array_map(static function (array $i) use ($proofs): array {
            $list = $proofs[(int) $i['id']] ?? [];
            $pending = count(array_filter($list, static fn (array $p): bool => $p['status'] === ProofStatus::Pending->value));
            $i['proofs'] = $list;
            $i['canUploadProof'] = !empty($i['isOpen']) && !empty($i['hasDocument']) && $pending < self::MAX_PENDING && count($list) < self::MAX_TOTAL;
            return $i;
        }, $presented);
    }

    /**
     * The payment journal of the edition (FEE-13).
     *
     * @return array{items: list<array<string, mixed>>, itemsMax: int}|null
     */
    public function journal(ContextInfo $context, int $editionId, int $count, int $offset, string $locale): ?array
    {
        if ($this->invoices->edition($context, $editionId) === null) {
            return null;
        }
        $page = $this->payments->editionEvents($context->id, $editionId, $count, $offset);
        $page['items'] = array_map(function (array $e) use ($locale): array {
            $payload = is_array($e['payload']) ? $e['payload'] : [];
            $amount = is_int($payload['amount'] ?? null) ? $payload['amount'] : null;
            return $e + [
                'typeLabel' => $this->t('plugins.generic.conferenceManager.invoice.event.' . $e['type'], [], $locale),
                'amount' => $amount === null ? null : (new Money($amount, (string) $e['currency']))->toArray(),
                'note' => is_string($payload['note'] ?? null) ? $payload['note'] : (is_string($payload['reason'] ?? null) ? $payload['reason'] : null),
            ];
        }, $page['items']);
        return $page;
    }

    /** @return array<string, mixed> */
    private function presentProof(ContextInfo $context, PaymentProof $p, string $locale): array
    {
        return [
            'id' => $p->id,
            'invoiceId' => $p->invoiceId,
            'name' => $p->originalName,
            'mime' => $p->mime,
            'size' => $p->size,
            'status' => $p->status->value,
            'statusLabel' => $this->t($p->status->labelKey(), [], $locale),
            'note' => $p->note,
            'uploadedAt' => DateInput::toIsoUtc($p->createdAt),
            'reviewedAt' => DateInput::toIsoUtc($p->reviewedAt),
        ];
    }

    private function notify(ContextInfo $context, int $invoiceId, bool $accepted, ?string $note): void
    {
        $invoice = $this->invoices->find($context, $invoiceId);
        if ($invoice === null || $this->notifier === null) {
            return;
        }
        try {
            $this->notifier->proofReviewed($context, $invoice, $this->invoices->present($context, $invoice, $context->primaryLocale), $accepted, $note);
        } catch (\Throwable $e) {
            error_log('conferenceManager: receipt review letter failed: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $params */
    private function t(string $key, array $params, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, $params, $locale);
    }
}
