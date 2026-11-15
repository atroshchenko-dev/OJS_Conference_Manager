<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PaymentFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class DbParticipantRepository implements ParticipantRepository
{
    /** Invoice states that still wait for money. */
    private const UNPAID = ['draft', 'issued', 'overdue'];

    public function __construct(private readonly Connection $db)
    {
    }

    public function addListener(int $contextId, int $editionId, array $person, ?int $categoryId, ParticipationForm $form, string $consentVersion, DateTimeImmutable $now, bool $minor = false): ?array
    {
        return $this->db->transaction(function () use ($contextId, $editionId, $person, $categoryId, $form, $consentVersion, $now, $minor): ?array {
            $stamp = DateInput::toStorage($now);
            // EVT-04: a minor keeps the minimum of data; the guardian consent is confirmed now.
            $minorValues = $minor ? ['is_minor' => true, 'guardian_consent_at' => $stamp] : [];
            if ($minor) {
                $person['position'] = null;
            }
            $email = mb_strtolower(trim($person['email']));
            $row = $this->db->table('conf_participants')->where('edition_id', $editionId)->where('email', $email)->lockForUpdate()->first();
            if ($row === null) {
                $participantId = (int) $this->db->table('conf_participants')->insertGetId([
                    'context_id' => $contextId,
                    'edition_id' => $editionId,
                    'user_id' => $person['userId'],
                    'email' => $email,
                    'affiliation' => self::cut($person['affiliation']),
                    'position' => self::cut($person['position']),
                    'country' => $person['country'] === null ? null : substr(strtoupper($person['country']), 0, 2),
                    'orcid' => self::cut($person['orcid']),
                    'category_id' => $categoryId,
                    'confirmation_status' => ConfirmationStatus::Confirmed->value,
                    'confirmed_at' => $stamp,
                    'consent_version' => $consentVersion,
                    'consent_at' => $stamp,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ] + $minorValues);
            } else {
                $participantId = (int) $row->id;
                $active = $this->db->table('conf_participations')->where('participant_id', $participantId)
                    ->where('role', ParticipantRole::Listener->value)->where('status', ParticipationStatus::Active->value)->exists();
                if ($active) {
                    return null;
                }
                // The person registers themselves: this is their confirmation and consent.
                $this->db->table('conf_participants')->where('id', $participantId)->update(array_filter([
                    'user_id' => $row->user_id ?? $person['userId'],
                    'affiliation' => self::cut($person['affiliation']) ?? $row->affiliation,
                    'position' => self::cut($person['position']) ?? $row->position,
                    'category_id' => $categoryId ?? $row->category_id,
                    'confirmation_status' => ConfirmationStatus::Confirmed->value,
                    'confirmed_at' => $row->confirmed_at ?? $stamp,
                    'confirmation_token_hash' => null,
                    'confirmation_expires_at' => null,
                    'consent_version' => $consentVersion,
                    'consent_at' => $stamp,
                    'updated_at' => $stamp,
                ], static fn ($v): bool => $v !== null) + ['confirmation_token_hash' => null, 'confirmation_expires_at' => null] + $minorValues);
            }
            foreach (['givenName' => $person['givenName'], 'familyName' => $person['familyName']] as $name => $values) {
                foreach ($values as $locale => $value) {
                    if (trim($value) !== '') {
                        $this->db->table('conf_participant_settings')->updateOrInsert(
                            ['participant_id' => $participantId, 'locale' => $locale, 'setting_name' => $name],
                            ['setting_value' => trim($value)],
                        );
                    }
                }
            }
            $participationId = (int) $this->db->table('conf_participations')->insertGetId([
                'participant_id' => $participantId,
                'submission_id' => null,
                'role' => ParticipantRole::Listener->value,
                'participation_form' => $form->value,
                'status' => ParticipationStatus::Active->value,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            return ['participantId' => $participantId, 'participationId' => $participationId];
        });
    }

    public function ofPerson(int $contextId, int $userId, string $email): array
    {
        $email = mb_strtolower(trim($email));
        $rows = $this->db->table('conf_participations as pn')
            ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('p.context_id', $contextId)
            ->where(static function (Builder $q) use ($userId, $email): void {
                $q->where('p.user_id', $userId);
                if ($email !== '') {
                    $q->orWhere('p.email', $email);
                }
            })
            ->orderByDesc('pn.id')
            ->get(['pn.id as participation_id', 'p.id as participant_id', 'p.edition_id', 'p.category_id', 'p.confirmation_status',
                'pn.role', 'pn.status', 'pn.participation_form', 'pn.submission_id', 'pn.created_at'])->all();
        return array_map(static fn (object $r): array => [
            'participationId' => (int) $r->participation_id,
            'participantId' => (int) $r->participant_id,
            'editionId' => (int) $r->edition_id,
            'categoryId' => $r->category_id === null ? null : (int) $r->category_id,
            'confirmationStatus' => (string) $r->confirmation_status,
            'role' => (string) $r->role,
            'status' => (string) $r->status,
            'participationForm' => (string) $r->participation_form,
            'submissionId' => $r->submission_id === null ? null : (int) $r->submission_id,
            'createdAt' => DateInput::toIsoUtc(DateInput::fromStorage($r->created_at === null ? null : (string) $r->created_at)),
        ], $rows);
    }

    public function findParticipation(int $contextId, int $participationId): ?array
    {
        $r = $this->db->table('conf_participations as pn')
            ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('p.context_id', $contextId)->where('pn.id', $participationId)
            ->first(['pn.id', 'pn.participant_id', 'p.edition_id', 'p.user_id', 'p.email', 'pn.role', 'pn.status', 'pn.submission_id']);
        if ($r === null) {
            return null;
        }
        return [
            'participationId' => (int) $r->id,
            'participantId' => (int) $r->participant_id,
            'editionId' => (int) $r->edition_id,
            'userId' => $r->user_id === null ? null : (int) $r->user_id,
            'email' => (string) $r->email,
            'role' => (string) $r->role,
            'status' => (string) $r->status,
            'submissionId' => $r->submission_id === null ? null : (int) $r->submission_id,
        ];
    }

    public function cancel(int $participationId, DateTimeImmutable $now): void
    {
        $this->db->table('conf_participations')->where('id', $participationId)->update([
            'status' => ParticipationStatus::Cancelled->value,
            'updated_at' => DateInput::toStorage($now),
        ]);
    }

    public function hasActiveParticipations(int $participantId): bool
    {
        return $this->db->table('conf_participations')->where('participant_id', $participantId)
            ->where('status', ParticipationStatus::Active->value)->exists();
    }

    public function settleInvoices(int $participantId, int $editionId, ?int $actorId, DateTimeImmutable $now): array
    {
        $out = ['cancelled' => 0, 'refundRequested' => 0];
        $stamp = DateInput::toStorage($now);
        $invoices = $this->db->table('conf_invoices')->where('payer_participant_id', $participantId)->where('edition_id', $editionId)
            ->whereNotIn('status', ['cancelled', 'superseded', 'refund_requested', 'refunded'])->get(['id', 'status', 'paid_minor'])->all();
        foreach ($invoices as $invoice) {
            $to = (int) $invoice->paid_minor > 0 || $invoice->status === 'paid' ? 'refund_requested' : 'cancelled';
            if ($to === 'cancelled' && !in_array($invoice->status, self::UNPAID, true) && $invoice->status !== 'waived') {
                continue;
            }
            $this->db->table('conf_invoices')->where('id', $invoice->id)->update(['status' => $to, 'updated_at' => $stamp]);
            $this->db->table('conf_payment_events')->insert([
                'invoice_id' => $invoice->id,
                'type' => 'status_' . $to,
                'actor_user_id' => $actorId,
                'payload' => json_encode(['from' => $invoice->status, 'reason' => 'registration_cancelled']),
                'created_at' => $stamp,
            ]);
            $out[$to === 'cancelled' ? 'cancelled' : 'refundRequested']++;
        }
        return $out;
    }

    /**
     * The current invoice of a participation: one with its item, or with
     * the item of its submission when the participant is the payer.
     */
    private function paymentFilter(Builder $query, PaymentFilter $payment): void
    {
        $current = static function (Builder $s, array $statuses, bool $pendingProof = false): void {
            $s->selectRaw('1')->from('conf_invoices as ci')
                ->join('conf_invoice_items as cit', 'cit.invoice_id', '=', 'ci.id')
                ->whereIn('ci.status', $statuses)
                ->where(static function (Builder $w): void {
                    $w->whereColumn('cit.participation_id', 'pn.id')
                        ->orWhere(static function (Builder $x): void {
                            $x->whereNotNull('pn.submission_id')->whereColumn('cit.submission_id', 'pn.submission_id')->whereColumn('ci.payer_participant_id', 'p.id');
                        });
                });
            if ($pendingProof) {
                $s->whereExists(static function (Builder $pr): void {
                    $pr->selectRaw('1')->from('conf_payment_proofs as cpp')->whereColumn('cpp.invoice_id', 'ci.id')->where('cpp.review_status', ProofStatus::Pending->value);
                });
            }
        };
        $open = [InvoiceStatus::Draft->value, InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value];
        $settled = [InvoiceStatus::Paid->value, InvoiceStatus::Waived->value];
        $all = array_values(array_filter(array_map(static fn (InvoiceStatus $s): string => $s->value, InvoiceStatus::cases()), static fn (string $v): bool => InvoiceStatus::from($v)->isCurrent()));
        match ($payment) {
            PaymentFilter::Paid => $query->whereExists(static fn (Builder $s) => $current($s, $settled))->whereNotExists(static fn (Builder $s) => $current($s, $open)),
            PaymentFilter::Unpaid => $query->whereExists(static fn (Builder $s) => $current($s, $open)),
            PaymentFilter::ProofPending => $query->whereExists(static fn (Builder $s) => $current($s, $open, true)),
            PaymentFilter::None => $query->whereNotExists(static fn (Builder $s) => $current($s, $all)),
        };
    }

    public function register(int $contextId, int $editionId, ParticipantFilter $filter, string $locale): array
    {
        $query = $this->db->table('conf_participations as pn')
            ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('p.context_id', $contextId)->where('p.edition_id', $editionId);
        if ($filter->role !== null) {
            $query->where('pn.role', $filter->role->value);
        }
        if ($filter->categoryId !== null) {
            $filter->categoryId === 0 ? $query->whereNull('p.category_id') : $query->where('p.category_id', $filter->categoryId);
        }
        if ($filter->status !== null) {
            $query->where('pn.status', $filter->status->value);
        }
        if ($filter->confirmation !== null) {
            $query->where('p.confirmation_status', $filter->confirmation->value);
        }
        if ($filter->payment !== null) {
            $this->paymentFilter($query, $filter->payment);
        }
        $search = trim($filter->search);
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)) . '%';
            $query->where(static function (Builder $q) use ($like): void {
                $q->whereRaw('LOWER(p.email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(p.affiliation) LIKE ?', [$like])
                    ->orWhereExists(static function (Builder $s) use ($like): void {
                        $s->selectRaw('1')->from('conf_participant_settings as ps')
                            ->whereColumn('ps.participant_id', 'p.id')
                            ->whereIn('ps.setting_name', ['givenName', 'familyName'])
                            ->whereRaw('LOWER(ps.setting_value) LIKE ?', [$like]);
                    });
            });
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('p.id')->orderBy('pn.id')
            ->limit($filter->limit())->offset(max(0, $filter->offset))
            ->get(['pn.id as participation_id', 'p.id as participant_id', 'p.email', 'p.affiliation', 'p.position', 'p.country',
                'p.category_id', 'p.confirmation_status', 'p.user_id', 'p.is_minor', 'p.guardian_consent_at', 'pn.role', 'pn.status', 'pn.participation_form', 'pn.submission_id', 'pn.created_at'])->all();
        $names = $this->names(array_map(static fn (object $r): int => (int) $r->participant_id, $rows), $locale);
        $items = array_map(static fn (object $r): array => [
            'participationId' => (int) $r->participation_id,
            'participantId' => (int) $r->participant_id,
            'name' => $names[(int) $r->participant_id] ?? '',
            'email' => (string) $r->email,
            'affiliation' => (string) ($r->affiliation ?? ''),
            'position' => (string) ($r->position ?? ''),
            'country' => (string) ($r->country ?? ''),
            'categoryId' => $r->category_id === null ? null : (int) $r->category_id,
            'confirmationStatus' => (string) $r->confirmation_status,
            'hasAccount' => $r->user_id !== null,
            'minor' => (bool) $r->is_minor,
            'guardianConsentAt' => DateInput::toIsoUtc(DateInput::fromStorage($r->guardian_consent_at === null ? null : (string) $r->guardian_consent_at)),
            'role' => (string) $r->role,
            'status' => (string) $r->status,
            'participationForm' => (string) $r->participation_form,
            'submissionId' => $r->submission_id === null ? null : (int) $r->submission_id,
            'createdAt' => DateInput::toIsoUtc(DateInput::fromStorage($r->created_at === null ? null : (string) $r->created_at)),
        ], $rows);
        return ['items' => $items, 'itemsMax' => $total];
    }

    public function setCategory(int $participantId, ?int $categoryId): void
    {
        $this->db->table('conf_participants')->where('id', $participantId)->update(['category_id' => $categoryId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function payer(int $participantId, string $locale): ?array
    {
        $p = $this->db->table('conf_participants')->where('id', $participantId)->first();
        if ($p === null) {
            return null;
        }
        return [
            'participantId' => (int) $p->id,
            'editionId' => (int) $p->edition_id,
            'userId' => $p->user_id === null ? null : (int) $p->user_id,
            'name' => $this->names([(int) $p->id], $locale)[(int) $p->id] ?? '',
            'email' => (string) $p->email,
            'affiliation' => (string) ($p->affiliation ?? ''),
            'categoryId' => $p->category_id === null ? null : (int) $p->category_id,
        ];
    }

    public function presenterOf(int $submissionId): ?int
    {
        $id = $this->db->table('conf_participations')->where('submission_id', $submissionId)
            ->where('role', ParticipantRole::Presenter->value)->where('status', ParticipationStatus::Active->value)
            ->orderBy('id')->value('id');
        return $id === null ? null : (int) $id;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> "Given Family" in the locale, else in any locale
     */
    private function names(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $parts = [];
        foreach ($this->db->table('conf_participant_settings')->whereIn('participant_id', array_values(array_unique($ids)))
            ->whereIn('setting_name', ['givenName', 'familyName'])->get() as $s) {
            $parts[(int) $s->participant_id][(string) $s->setting_name][(string) $s->locale] = (string) $s->setting_value;
        }
        $out = [];
        foreach ($parts as $id => $p) {
            $pick = static fn (array $v): string => $v[$locale] ?? (array_values($v)[0] ?? '');
            $out[$id] = trim($pick($p['givenName'] ?? []) . ' ' . $pick($p['familyName'] ?? []));
        }
        return $out;
    }

    private static function cut(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === null || $value === '' ? null : mb_substr($value, 0, 255);
    }
}
