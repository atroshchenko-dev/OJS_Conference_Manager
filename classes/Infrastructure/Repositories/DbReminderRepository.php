<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ReminderRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

final class DbReminderRepository implements ReminderRepository
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function unpaidInvoices(int $editionId, string $locale): array
    {
        $rows = $this->db->table('conf_invoices as i')
            ->where('i.edition_id', $editionId)
            ->whereIn('i.status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])
            ->where('i.method', '!=', 'waiver')
            ->whereColumn('i.total_minor', '>', 'i.paid_minor')
            ->whereNotExists(static function (Builder $q): void {
                $q->selectRaw('1')->from('conf_payment_proofs as pr')->whereColumn('pr.invoice_id', 'i.id')->where('pr.review_status', ProofStatus::Pending->value);
            })
            ->orderBy('i.id')
            ->get(['i.id', 'i.number', 'i.total_minor', 'i.paid_minor', 'i.currency', 'i.due_at', 'i.payment_reference', 'i.payer_snapshot', 'i.payer_participant_id'])->all();
        return array_map(static function (object $r): array {
            $payer = (array) (json_decode((string) ($r->payer_snapshot ?? '[]'), true) ?: []);
            return [
                'invoiceId' => (int) $r->id,
                'email' => (string) ($payer['email'] ?? ''),
                'name' => (string) ($payer['name'] ?? ''),
                'number' => (string) $r->number,
                'dueMinor' => (int) $r->total_minor - (int) $r->paid_minor,
                'currency' => (string) $r->currency,
                'dueAt' => DateInput::fromStorage($r->due_at === null ? null : (string) $r->due_at),
                'reference' => (string) ($r->payment_reference ?? ''),
                'participantId' => (int) $r->payer_participant_id,
            ];
        }, $rows);
    }

    public function participations(int $editionId, string $locale): array
    {
        $rows = $this->db->table('conf_participations as pn')
            ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('p.edition_id', $editionId)
            ->where('pn.status', ParticipationStatus::Active->value)
            ->orderBy('p.id')->orderBy('pn.id')
            ->get(['p.id as participant_id', 'p.email', 'pn.role', 'pn.submission_id'])->all();
        $names = [];
        $ids = array_values(array_unique(array_map(static fn (object $r): int => (int) $r->participant_id, $rows)));
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->table('conf_participant_settings')->whereIn('participant_id', $chunk)->whereIn('setting_name', ['givenName', 'familyName'])->get() as $s) {
                $names[(int) $s->participant_id][(string) $s->setting_name][(string) $s->locale] = (string) $s->setting_value;
            }
        }
        $pick = static fn (array $v): string => $v[$locale] ?? (array_values($v)[0] ?? '');
        return array_map(static fn (object $r): array => [
            'participantId' => (int) $r->participant_id,
            'email' => (string) $r->email,
            'name' => trim($pick($names[(int) $r->participant_id]['givenName'] ?? []) . ' ' . $pick($names[(int) $r->participant_id]['familyName'] ?? [])),
            'role' => (string) $r->role,
            'submissionId' => $r->submission_id === null ? null : (int) $r->submission_id,
        ], $rows);
    }

    public function claim(int $contextId, int $editionId, string $kind, int $targetId, string $email, DateTimeImmutable $now): bool
    {
        if ($this->db->table('conf_reminders_sent')->where('kind', $kind)->where('target_id', $targetId)->exists()) {
            return false;
        }
        try {
            $this->db->table('conf_reminders_sent')->insert([
                'context_id' => $contextId,
                'edition_id' => $editionId,
                'kind' => $kind,
                'target_id' => $targetId,
                'email' => mb_substr($email, 0, 255),
                'sent_at' => DateInput::toStorage($now),
            ]);
            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function release(string $kind, int $targetId): void
    {
        $this->db->table('conf_reminders_sent')->where('kind', $kind)->where('target_id', $targetId)->delete();
    }

    public function sentCounts(int $editionId): array
    {
        $out = [];
        foreach ($this->db->table('conf_reminders_sent')->where('edition_id', $editionId)->groupBy('kind')->selectRaw('kind, COUNT(*) as n')->get() as $r) {
            $out[(string) $r->kind] = (int) $r->n;
        }
        return $out;
    }
}
