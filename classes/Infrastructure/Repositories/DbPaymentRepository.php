<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Invoice;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceItem;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceNumber;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentProof;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentReference;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\PaymentRule;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class DbPaymentRepository implements PaymentRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function rules(int $editionId): array
    {
        $rows = $this->db->table('conf_payment_rules')->where('edition_id', $editionId)
            ->orderByRaw('CASE WHEN category_id IS NULL THEN 0 ELSE 1 END')->orderBy('id')->get()->all();
        $texts = $this->instructions(array_map(static fn (object $r): int => (int) $r->id, $rows));
        return array_map(fn (object $r): PaymentRule => $this->rule($r, $texts[(int) $r->id] ?? []), $rows);
    }

    public function findRule(int $editionId, int $id): ?PaymentRule
    {
        $r = $this->db->table('conf_payment_rules')->where('edition_id', $editionId)->where('id', $id)->first();
        return $r === null ? null : $this->rule($r, $this->instructions([$id])[$id] ?? []);
    }

    public function saveRule(PaymentRule $rule): PaymentRule
    {
        return $this->db->transaction(function () use ($rule): PaymentRule {
            $now = gmdate('Y-m-d H:i:s');
            $values = [
                'category_id' => $rule->categoryId,
                'mode' => $rule->mode->value,
                'payment_account_id' => $rule->paymentAccountId,
                'external_url' => $rule->externalUrl,
                'updated_at' => $now,
            ];
            if ($rule->id === null) {
                $id = (int) $this->db->table('conf_payment_rules')->insertGetId($values + ['edition_id' => $rule->editionId, 'created_at' => $now]);
            } else {
                $id = $rule->id;
                $this->db->table('conf_payment_rules')->where('id', $id)->where('edition_id', $rule->editionId)->update($values);
            }
            $this->db->table('conf_payment_rule_settings')->where('rule_id', $id)->delete();
            foreach ($rule->instructions->toArray() as $locale => $text) {
                if (trim($text) !== '') {
                    $this->db->table('conf_payment_rule_settings')->insert(['rule_id' => $id, 'locale' => $locale, 'setting_name' => 'instructions', 'setting_value' => $text]);
                }
            }
            return $rule->withId($id);
        });
    }

    public function deleteRule(int $editionId, int $id): bool
    {
        return $this->db->table('conf_payment_rules')->where('edition_id', $editionId)->where('id', $id)->delete() > 0;
    }

    /**
     * Creates the counter row of the year. Call it outside a transaction:
     * INSERT IGNORE on an existing row takes a shared lock in InnoDB, and
     * two transactions holding it would deadlock on SELECT … FOR UPDATE.
     */
    public function ensureCounter(int $contextId, int $year): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $key = ['context_id' => $contextId, 'kind' => 'invoice', 'year' => $year];
        if (!$this->db->table('conf_counters')->where($key)->exists()) {
            $this->db->table('conf_counters')->insertOrIgnore($key + ['last_value' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function nextNumber(int $contextId, int $year): int
    {
        $key = ['context_id' => $contextId, 'kind' => 'invoice', 'year' => $year];
        $row = $this->db->table('conf_counters')->where($key)->lockForUpdate()->first();
        if ($row === null) {
            // Not prepared by ensureCounter(): INSERT IGNORE does not abort
            // a PostgreSQL transaction when another request created the row.
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('conf_counters')->insertOrIgnore($key + ['last_value' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $row = $this->db->table('conf_counters')->where($key)->lockForUpdate()->first();
        }
        $next = (int) $row->last_value + 1;
        $this->db->table('conf_counters')->where('id', $row->id)->update(['last_value' => $next, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return $next;
    }

    public function createInvoice(Invoice $invoice, ?int $actorId, DateTimeImmutable $now): Invoice
    {
        $this->ensureCounter($invoice->contextId, (int) $now->format('Y'));
        // Attempts: a deadlock or serialization failure retries the whole issue.
        return $this->db->transaction(function () use ($invoice, $actorId, $now): Invoice {
            $stamp = DateInput::toStorage($now);
            $year = (int) $now->format('Y');
            $seq = $this->nextNumber($invoice->contextId, $year);
            $number = InvoiceNumber::format($year, $seq);
            do {
                $reference = PaymentReference::generate();
            } while ($this->db->table('conf_invoices')->where('payment_reference', $reference)->exists());

            $id = (int) $this->db->table('conf_invoices')->insertGetId([
                'context_id' => $invoice->contextId,
                'edition_id' => $invoice->editionId,
                'payer_participant_id' => $invoice->payerParticipantId,
                'payment_account_id' => $invoice->paymentAccountId,
                'number_year' => $year,
                'number' => $number,
                'payment_reference' => $reference,
                'status' => $invoice->status->value,
                'method' => $invoice->method,
                'mode' => $invoice->mode->value,
                'total_minor' => $invoice->total->minor,
                'paid_minor' => $invoice->paidAmount()->minor,
                'currency' => $invoice->total->currency,
                'waiver_reason' => $invoice->waiverReason,
                'supersedes_invoice_id' => $invoice->supersedesId,
                'due_at' => DateInput::toStorage($invoice->dueAt),
                'issued_at' => $stamp,
                'payer_snapshot' => json_encode($invoice->payer, JSON_UNESCAPED_UNICODE),
                'recipient_snapshot' => json_encode($invoice->recipient, JSON_UNESCAPED_UNICODE),
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            foreach ($invoice->items as $item) {
                $this->db->table('conf_invoice_items')->insert([
                    'invoice_id' => $id,
                    'participation_id' => $item->participationId,
                    'submission_id' => $item->submissionId,
                    'description_snapshot' => $item->description,
                    'tariff_id' => $item->tariffId,
                    'quantity' => $item->quantity,
                    'unit_minor' => $item->unit->minor,
                    'amount_minor' => $item->amount->minor,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
            }
            if ($invoice->supersedesId !== null) {
                $this->changeStatus($invoice->supersedesId, InvoiceStatus::Superseded, [], 'superseded', $actorId, ['by' => $id], $now);
            }
            $this->event($id, $invoice->status === InvoiceStatus::Waived ? 'waived' : 'issued', $actorId, ['total' => $invoice->total->minor, 'number' => $number], $stamp);
            return $invoice->with(['id' => $id, 'numberYear' => $year, 'number' => $number, 'paymentReference' => $reference, 'issuedAt' => $now]);
        }, 3);
    }

    public function findInvoice(int $contextId, int $id): ?Invoice
    {
        $r = $this->db->table('conf_invoices')->where('context_id', $contextId)->where('id', $id)->first();
        return $r === null ? null : $this->hydrate([$r])[0];
    }

    public function changeStatus(int $invoiceId, InvoiceStatus $to, array $changes, string $event, ?int $actorId, array $payload, DateTimeImmutable $now): void
    {
        $stamp = DateInput::toStorage($now);
        $this->db->transaction(function () use ($invoiceId, $to, $changes, $event, $actorId, $payload, $stamp): void {
            $from = $this->db->table('conf_invoices')->where('id', $invoiceId)->lockForUpdate()->value('status');
            $allowed = ['paid_minor', 'paid_at', 'waiver_reason', 'method'];
            $values = array_intersect_key($changes, array_flip($allowed));
            $this->db->table('conf_invoices')->where('id', $invoiceId)->update($values + ['status' => $to->value, 'updated_at' => $stamp]);
            $this->event($invoiceId, $event, $actorId, $payload + ['from' => $from, 'to' => $to->value], $stamp);
        });
    }

    public function currentFor(int $participationId, ?int $submissionId = null): ?Invoice
    {
        $q = $this->db->table('conf_invoices as i')
            ->join('conf_invoice_items as it', 'it.invoice_id', '=', 'i.id')
            ->whereNotIn('i.status', [InvoiceStatus::Cancelled->value, InvoiceStatus::Superseded->value]);
        $submissionId === null
            ? $q->where('it.participation_id', $participationId)
            : $q->where(static fn (Builder $w) => $w->where('it.participation_id', $participationId)->orWhere('it.submission_id', $submissionId));
        $r = $q->orderByDesc('i.id')->select('i.*')->first();
        return $r === null ? null : $this->hydrate([$r])[0];
    }

    public function ofParticipants(array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }
        return $this->hydrate($this->db->table('conf_invoices')->whereIn('payer_participant_id', $participantIds)->orderByDesc('id')->get()->all());
    }

    public function ofEdition(int $contextId, int $editionId, ?InvoiceStatus $status, string $search, int $count, int $offset): array
    {
        $q = $this->db->table('conf_invoices as i')->where('i.context_id', $contextId)->where('i.edition_id', $editionId);
        if ($status !== null) {
            $q->where('i.status', $status->value);
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)) . '%';
            $q->where(static function (Builder $w) use ($like, $search): void {
                $w->whereRaw('LOWER(i.number) LIKE ?', [$like])
                    ->orWhere('i.payment_reference', strtoupper($search))
                    ->orWhereExists(static function (Builder $s) use ($like): void {
                        $s->selectRaw('1')->from('conf_participants as p')->whereColumn('p.id', 'i.payer_participant_id')
                            ->whereRaw('LOWER(p.email) LIKE ?', [$like]);
                    });
            });
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('i.id')->limit(max(1, min(50, $count)))->offset(max(0, $offset))->select('i.*')->get()->all();
        return ['items' => $this->hydrate($rows), 'itemsMax' => $total];
    }

    public function events(int $invoiceId): array
    {
        return array_map(static fn (object $e): array => [
            'type' => (string) $e->type,
            'actorUserId' => $e->actor_user_id === null ? null : (int) $e->actor_user_id,
            'payload' => json_decode((string) ($e->payload ?? 'null'), true),
            'createdAt' => DateInput::toIsoUtc(DateInput::fromStorage((string) $e->created_at)),
        ], $this->db->table('conf_payment_events')->where('invoice_id', $invoiceId)->orderBy('id')->get()->all());
    }

    public function addProof(PaymentProof $proof, DateTimeImmutable $now): PaymentProof
    {
        $stamp = DateInput::toStorage($now);
        $id = $this->db->transaction(function () use ($proof, $stamp): int {
            $id = (int) $this->db->table('conf_payment_proofs')->insertGetId([
                'invoice_id' => $proof->invoiceId,
                'uploaded_by' => $proof->uploadedBy,
                'file_path' => $proof->filePath,
                'original_name' => $proof->originalName,
                'mime' => $proof->mime,
                'size' => $proof->size,
                'sha256' => $proof->sha256,
                'review_status' => ProofStatus::Pending->value,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $this->event($proof->invoiceId, 'proof_uploaded', $proof->uploadedBy, ['proofId' => $id, 'name' => $proof->originalName, 'size' => $proof->size], $stamp);
            return $id;
        });
        return new PaymentProof($id, $proof->invoiceId, $proof->uploadedBy, $proof->filePath, $proof->originalName, $proof->mime, $proof->size, $proof->sha256, ProofStatus::Pending, null, null, null, $now);
    }

    public function findProof(int $contextId, int $id): ?PaymentProof
    {
        $r = $this->db->table('conf_payment_proofs as pr')
            ->join('conf_invoices as i', 'i.id', '=', 'pr.invoice_id')
            ->where('i.context_id', $contextId)->where('pr.id', $id)
            ->select('pr.*')->first();
        return $r === null ? null : self::proof($r);
    }

    public function proofsOf(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('conf_payment_proofs')->whereIn('invoice_id', $invoiceIds)->orderBy('id')->get() as $r) {
            $out[(int) $r->invoice_id][] = self::proof($r);
        }
        return $out;
    }

    public function proofQueue(int $contextId, ?int $editionId, ?ProofStatus $status, int $count, int $offset): array
    {
        $q = $this->db->table('conf_payment_proofs as pr')
            ->join('conf_invoices as i', 'i.id', '=', 'pr.invoice_id')
            ->where('i.context_id', $contextId);
        if ($editionId !== null) {
            $q->where('i.edition_id', $editionId);
        }
        if ($status !== null) {
            $q->where('pr.review_status', $status->value);
        }
        $total = (clone $q)->count();
        $rows = $q->orderBy('pr.id')->limit(max(1, min(50, $count)))->offset(max(0, $offset))->select('pr.*')->get()->all();
        $invoices = [];
        $ids = array_values(array_unique(array_map(static fn (object $r): int => (int) $r->invoice_id, $rows)));
        if ($ids !== []) {
            foreach ($this->hydrate($this->db->table('conf_invoices')->whereIn('id', $ids)->get()->all()) as $invoice) {
                $invoices[(int) $invoice->id] = $invoice;
            }
        }
        $items = [];
        foreach ($rows as $r) {
            $invoice = $invoices[(int) $r->invoice_id] ?? null;
            if ($invoice !== null) {
                $items[] = ['proof' => self::proof($r), 'invoice' => $invoice];
            }
        }
        return ['items' => $items, 'itemsMax' => $total];
    }

    public function sameFile(int $contextId, string $sha256, ?int $exceptProofId): array
    {
        $q = $this->db->table('conf_payment_proofs as pr')
            ->join('conf_invoices as i', 'i.id', '=', 'pr.invoice_id')
            ->where('i.context_id', $contextId)->where('pr.sha256', $sha256);
        if ($exceptProofId !== null) {
            $q->where('pr.id', '<>', $exceptProofId);
        }
        return array_values(array_unique(array_map(static fn (mixed $v): int => (int) $v, $q->pluck('pr.invoice_id')->all())));
    }

    public function reviewProof(int $proofId, ProofStatus $to, ?int $reviewerId, ?string $note, string $event, array $payload, DateTimeImmutable $now): bool
    {
        $stamp = DateInput::toStorage($now);
        return $this->db->transaction(function () use ($proofId, $to, $reviewerId, $note, $event, $payload, $stamp): bool {
            $invoiceId = $this->db->table('conf_payment_proofs')->where('id', $proofId)->value('invoice_id');
            $changed = $this->db->table('conf_payment_proofs')
                ->where('id', $proofId)->where('review_status', ProofStatus::Pending->value)
                ->update(['review_status' => $to->value, 'reviewed_by' => $reviewerId, 'reviewed_at' => $stamp, 'note' => $note, 'updated_at' => $stamp]);
            if ($changed !== 1 || $invoiceId === null) {
                return false;
            }
            $this->event((int) $invoiceId, $event, $reviewerId, $payload + ['proofId' => $proofId], $stamp);
            return true;
        });
    }

    public function editionEvents(int $contextId, int $editionId, int $count, int $offset): array
    {
        $q = $this->db->table('conf_payment_events as e')
            ->join('conf_invoices as i', 'i.id', '=', 'e.invoice_id')
            ->where('i.context_id', $contextId)->where('i.edition_id', $editionId);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('e.id')->limit(max(1, min(100, $count)))->offset(max(0, $offset))
            ->get(['e.id', 'e.invoice_id', 'e.type', 'e.actor_user_id', 'e.payload', 'e.created_at', 'i.number', 'i.currency'])->all();
        return ['items' => array_map(static fn (object $e): array => [
            'id' => (int) $e->id,
            'invoiceId' => (int) $e->invoice_id,
            'invoiceNumber' => $e->number === null ? null : (string) $e->number,
            'currency' => (string) $e->currency,
            'type' => (string) $e->type,
            'actorUserId' => $e->actor_user_id === null ? null : (int) $e->actor_user_id,
            'payload' => json_decode((string) ($e->payload ?? 'null'), true),
            'createdAt' => DateInput::toIsoUtc(DateInput::fromStorage((string) $e->created_at)),
        ], $rows), 'itemsMax' => $total];
    }

    private static function proof(object $r): PaymentProof
    {
        return new PaymentProof(
            (int) $r->id,
            (int) $r->invoice_id,
            $r->uploaded_by === null ? null : (int) $r->uploaded_by,
            (string) $r->file_path,
            (string) $r->original_name,
            (string) $r->mime,
            (int) $r->size,
            (string) $r->sha256,
            ProofStatus::tryFrom((string) $r->review_status) ?? ProofStatus::Pending,
            $r->reviewed_by === null ? null : (int) $r->reviewed_by,
            DateInput::fromStorage($r->reviewed_at === null ? null : (string) $r->reviewed_at),
            $r->note === null ? null : (string) $r->note,
            DateInput::fromStorage($r->created_at === null ? null : (string) $r->created_at),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(int $invoiceId, string $type, ?int $actorId, array $payload, ?string $stamp): void
    {
        $this->db->table('conf_payment_events')->insert([
            'invoice_id' => $invoiceId,
            'type' => $type,
            'actor_user_id' => $actorId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => $stamp ?? gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param list<object> $rows
     * @return list<Invoice>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $items = [];
        foreach ($this->db->table('conf_invoice_items')->whereIn('invoice_id', array_map(static fn (object $r): int => (int) $r->id, $rows))->orderBy('id')->get() as $it) {
            $items[(int) $it->invoice_id][] = $it;
        }
        $out = [];
        foreach ($rows as $r) {
            $currency = (string) $r->currency;
            $date = static fn (mixed $v): ?DateTimeImmutable => DateInput::fromStorage($v === null ? null : (string) $v);
            $out[] = new Invoice(
                (int) $r->id,
                (int) $r->context_id,
                (int) $r->edition_id,
                (int) $r->payer_participant_id,
                PaymentMode::tryFrom((string) $r->mode) ?? PaymentMode::BankTransfer,
                InvoiceStatus::tryFrom((string) $r->status) ?? InvoiceStatus::Issued,
                new Money((int) $r->total_minor, $currency),
                array_map(static fn (object $it): InvoiceItem => new InvoiceItem(
                    (string) $it->description_snapshot,
                    (int) $it->quantity,
                    new Money((int) $it->unit_minor, $currency),
                    new Money((int) $it->amount_minor, $currency),
                    $it->participation_id === null ? null : (int) $it->participation_id,
                    $it->submission_id === null ? null : (int) $it->submission_id,
                    $it->tariff_id === null ? null : (int) $it->tariff_id,
                ), $items[(int) $r->id] ?? []),
                $r->payment_account_id === null ? null : (int) $r->payment_account_id,
                $r->number_year === null ? null : (int) $r->number_year,
                $r->number === null ? null : (string) $r->number,
                $r->payment_reference === null ? null : (string) $r->payment_reference,
                new Money((int) $r->paid_minor, $currency),
                $r->waiver_reason === null ? null : (string) $r->waiver_reason,
                $r->supersedes_invoice_id === null ? null : (int) $r->supersedes_invoice_id,
                $date($r->due_at),
                $date($r->issued_at),
                $date($r->paid_at),
                (array) (json_decode((string) ($r->payer_snapshot ?? '[]'), true) ?: []),
                (array) (json_decode((string) ($r->recipient_snapshot ?? '[]'), true) ?: []),
                (string) $r->method,
            );
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, string>>
     */
    private function instructions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('conf_payment_rule_settings')->whereIn('rule_id', $ids)->where('setting_name', 'instructions')->get() as $s) {
            $out[(int) $s->rule_id][(string) $s->locale] = (string) $s->setting_value;
        }
        return $out;
    }

    /**
     * @param array<string, string> $instructions
     */
    private function rule(object $r, array $instructions): PaymentRule
    {
        return new PaymentRule(
            (int) $r->id,
            (int) $r->edition_id,
            $r->category_id === null ? null : (int) $r->category_id,
            PaymentMode::tryFrom((string) $r->mode) ?? PaymentMode::Free,
            $r->payment_account_id === null ? null : (int) $r->payment_account_id,
            $r->external_url === null ? null : (string) $r->external_url,
            new LocalizedText($instructions),
        );
    }
}
