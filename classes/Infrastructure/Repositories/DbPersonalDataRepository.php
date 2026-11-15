<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Payment\ProofStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Privacy\PersonalDataRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final class DbPersonalDataRepository implements PersonalDataRepository
{
    /** DAT-06: every column of the plugin that holds an OJS user id. */
    public const USER_COLUMNS = [
        ['conf_participants', 'user_id'],
        ['conf_participations', 'attended_by'],
        ['conf_session_attendance', 'marked_by'],
        ['conf_payment_proofs', 'uploaded_by'],
        ['conf_payment_proofs', 'reviewed_by'],
        ['conf_payment_events', 'actor_user_id'],
        ['conf_audit_log', 'actor_user_id'],
        ['conf_mail_campaigns', 'created_by'],
        ['conf_campaigns', 'created_by'],
        ['conf_submission_registrations', 'consent_user_id'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public function participantsOf(int $contextId, ?int $userId, string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($userId === null && $email === '') {
            return [];
        }
        $q = $this->db->table('conf_participants')->where('context_id', $contextId)->whereNull('anonymized_at')
            ->where(function ($w) use ($userId, $email): void {
                if ($userId !== null) {
                    $w->orWhere('user_id', $userId);
                }
                if ($email !== '') {
                    $w->orWhere('email', $email);
                }
            });
        return array_map('intval', $q->orderBy('id')->pluck('id')->all());
    }

    public function export(array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }
        $rows = fn (string $table, string $column, array $ids, array $hidden = []): array => array_map(
            static fn (object $r): array => array_diff_key((array) $r, array_flip($hidden)),
            $ids === [] ? [] : $this->db->table($table)->whereIn($column, $ids)->orderBy('id')->get()->all(),
        );
        $participations = $rows('conf_participations', 'participant_id', $participantIds);
        $invoices = $rows('conf_invoices', 'payer_participant_id', $participantIds, ['idempotency_key', 'pdf_path']);
        $invoiceIds = array_map(static fn (array $i): int => (int) $i['id'], $invoices);
        $submissionIds = array_values(array_filter(array_map(static fn (array $p): ?int => $p['submission_id'] === null ? null : (int) $p['submission_id'], $participations)));
        $out = [
            'participants' => $rows('conf_participants', 'id', $participantIds, ['confirmation_token_hash']),
            'participantSettings' => array_map(
                static fn (object $r): array => (array) $r,
                $this->db->table('conf_participant_settings')->whereIn('participant_id', $participantIds)->orderBy('participant_id')->get()->all(),
            ),
            'participations' => $participations,
            'sessionAttendance' => $rows('conf_session_attendance', 'participation_id', array_map(static fn (array $p): int => (int) $p['id'], $participations)),
            'invoices' => $invoices,
            'invoiceItems' => $rows('conf_invoice_items', 'invoice_id', $invoiceIds),
            'paymentProofs' => $rows('conf_payment_proofs', 'invoice_id', $invoiceIds, ['file_path']),
            'paymentEvents' => $rows('conf_payment_events', 'invoice_id', $invoiceIds),
            'certificates' => $rows('conf_certificates', 'participant_id', $participantIds, ['pdf_path']),
            'mailLog' => $rows('conf_mail_log', 'participant_id', $participantIds),
            'campaignRecipients' => $rows('conf_campaign_recipients', 'participant_id', $participantIds),
            'submissionRegistrations' => $rows('conf_submission_registrations', 'submission_id', $submissionIds),
        ];
        $emails = array_values(array_unique(array_map(static fn (array $p): string => (string) $p['email'], $out['participants'])));
        $out['remindersSent'] = array_map(static fn (object $r): array => (array) $r, $this->db->table('conf_reminders_sent')->whereIn('email', $emails)->orderBy('id')->get()->all());
        return $out;
    }

    public function anonymize(int $contextId, int $participantId, string $placeholderName, DateTimeImmutable $now): bool
    {
        return $this->db->transaction(function () use ($contextId, $participantId, $placeholderName, $now): bool {
            $p = $this->db->table('conf_participants')->where('id', $participantId)->where('context_id', $contextId)->lockForUpdate()->first();
            if ($p === null || $p->anonymized_at !== null) {
                return false;
            }
            $stamp = DateInput::toStorage($now);
            $email = (string) $p->email;
            $this->db->table('conf_participants')->where('id', $participantId)->update([
                'user_id' => null,
                'author_id' => null,
                'email' => 'anonymized-' . $participantId . '@invalid',
                'affiliation' => null,
                'position' => null,
                'country' => null,
                'orcid' => null,
                'extra' => null,
                'confirmation_token_hash' => null,
                'anonymized_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $this->db->table('conf_participant_settings')->where('participant_id', $participantId)->delete();
            $this->db->table('conf_participant_settings')->insert([
                ['participant_id' => $participantId, 'locale' => '', 'setting_name' => 'familyName', 'setting_value' => $placeholderName],
            ]);
            $this->db->table('conf_campaign_recipients')->where('participant_id', $participantId)->update(['email' => '', 'name' => '']);
            if (!$this->db->table('conf_participants')->where('context_id', $contextId)->where('email', $email)->exists()) {
                $this->db->table('conf_reminders_sent')->where('context_id', $contextId)->where('email', $email)->update(['email' => '']);
            }
            return true;
        });
    }

    public function mergeUsers(int $oldUserId, int $newUserId): array
    {
        $out = [];
        if ($oldUserId === $newUserId) {
            return $out;
        }
        $this->db->transaction(function () use ($oldUserId, $newUserId, &$out): void {
            foreach (self::USER_COLUMNS as [$table, $column]) {
                $out[$table . '.' . $column] = $this->db->table($table)->where($column, $oldUserId)->update([$column => $newUserId]);
            }
        });
        return $out;
    }

    public function expiredProofs(int $contextId, DateTimeImmutable $uploadedBefore, int $limit): array
    {
        return array_map(static fn (object $r): array => ['id' => (int) $r->id, 'key' => (string) $r->file_path], $this->db->table('conf_payment_proofs as pp')
            ->join('conf_invoices as i', 'i.id', '=', 'pp.invoice_id')
            ->where('i.context_id', $contextId)
            ->whereNull('pp.purged_at')->whereNotNull('pp.file_path')
            ->where('pp.review_status', '!=', ProofStatus::Pending->value)
            ->where('pp.created_at', '<', DateInput::toStorage($uploadedBefore))
            ->orderBy('pp.id')->limit(max(1, $limit))->get(['pp.id', 'pp.file_path'])->all());
    }

    public function markProofPurged(int $proofId, DateTimeImmutable $now): void
    {
        $stamp = DateInput::toStorage($now);
        $this->db->table('conf_payment_proofs')->where('id', $proofId)->update(['file_path' => null, 'purged_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function participantsOfEndedEditions(int $contextId, DateTimeImmutable $endedBefore, int $limit): array
    {
        return array_map('intval', $this->db->table('conf_participants as p')
            ->join('conf_editions as e', 'e.id', '=', 'p.edition_id')
            ->where('p.context_id', $contextId)->whereNull('p.anonymized_at')
            ->whereNotNull('e.ends_at')->where('e.ends_at', '<', DateInput::toStorage($endedBefore))
            ->orderBy('p.id')->limit(max(1, $limit))->pluck('p.id')->all());
    }

    public function isAnonymized(int $participantId): bool
    {
        return $this->db->table('conf_participants')->where('id', $participantId)->whereNotNull('anonymized_at')->exists();
    }
}
