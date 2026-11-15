<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\AttendanceRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final class DbAttendanceRepository implements AttendanceRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function ofEdition(int $contextId, int $editionId, string $locale): array
    {
        $rows = $this->db->table('conf_participations as pn')
            ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('p.context_id', $contextId)->where('p.edition_id', $editionId)
            ->orderBy('p.id')->orderBy('pn.id')
            ->get(['pn.id', 'pn.participant_id', 'p.user_id', 'p.email', 'p.affiliation', 'pn.role', 'pn.status', 'pn.participation_form',
                'pn.submission_id', 'pn.attended', 'pn.attended_minutes', 'pn.attendance_source'])->all();
        $names = (new DbParticipantRepository($this->db))->names(array_map(static fn (object $r): int => (int) $r->participant_id, $rows), $locale);
        return array_map(static fn (object $r): array => [
            'participationId' => (int) $r->id,
            'participantId' => (int) $r->participant_id,
            'userId' => $r->user_id === null ? null : (int) $r->user_id,
            'email' => (string) $r->email,
            'name' => $names[(int) $r->participant_id] ?? '',
            'affiliation' => (string) ($r->affiliation ?? ''),
            'role' => (string) $r->role,
            'status' => (string) $r->status,
            'form' => (string) $r->participation_form,
            'submissionId' => $r->submission_id === null ? null : (int) $r->submission_id,
            'attended' => (bool) $r->attended,
            'minutes' => $r->attended_minutes === null ? null : (int) $r->attended_minutes,
            'source' => $r->attendance_source === null ? null : (string) $r->attendance_source,
        ], $rows);
    }

    public function mark(int $editionId, array $participationIds, bool $attended, ?int $minutes, string $source, ?int $actorId, DateTimeImmutable $now): int
    {
        if ($participationIds === []) {
            return 0;
        }
        $ids = $this->db->table('conf_participations as pn')
            ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
            ->where('p.edition_id', $editionId)->whereIn('pn.id', $participationIds)->pluck('pn.id')->all();
        if ($ids === []) {
            return 0;
        }
        $stamp = DateInput::toStorage($now);
        return $this->db->table('conf_participations')->whereIn('id', $ids)->update([
            'attended' => $attended,
            'attended_minutes' => $minutes,
            'attended_by' => $attended ? $actorId : null,
            'attended_at' => $attended ? $stamp : null,
            'attendance_source' => $source,
            'updated_at' => $stamp,
        ]);
    }

    public function addStaff(int $contextId, int $editionId, string $role, array $person, DateTimeImmutable $now): array
    {
        return $this->db->transaction(function () use ($contextId, $editionId, $role, $person, $now): array {
            $stamp = DateInput::toStorage($now);
            $email = mb_strtolower(trim($person['email']));
            $row = $this->db->table('conf_participants')->where('edition_id', $editionId)->where('email', $email)->lockForUpdate()->first();
            if ($row === null) {
                $participantId = (int) $this->db->table('conf_participants')->insertGetId([
                    'context_id' => $contextId,
                    'edition_id' => $editionId,
                    'user_id' => $person['userId'],
                    'email' => $email,
                    'affiliation' => $person['affiliation'] === '' ? null : mb_substr($person['affiliation'], 0, 255),
                    'confirmation_status' => ConfirmationStatus::Confirmed->value,
                    'confirmed_at' => $stamp,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
                foreach (['givenName' => $person['givenName'], 'familyName' => $person['familyName']] as $name => $value) {
                    if (trim($value) !== '') {
                        $this->db->table('conf_participant_settings')->insert(
                            ['participant_id' => $participantId, 'locale' => $person['locale'], 'setting_name' => $name, 'setting_value' => trim($value)],
                        );
                    }
                }
            } else {
                $participantId = (int) $row->id;
                if ($row->user_id === null && $person['userId'] !== null) {
                    $this->db->table('conf_participants')->where('id', $participantId)->update(['user_id' => $person['userId'], 'updated_at' => $stamp]);
                }
                $existing = $this->db->table('conf_participations')->where('participant_id', $participantId)
                    ->where('role', $role)->where('status', ParticipationStatus::Active->value)->value('id');
                if ($existing !== null) {
                    return ['participationId' => (int) $existing, 'created' => false];
                }
            }
            $id = (int) $this->db->table('conf_participations')->insertGetId([
                'participant_id' => $participantId,
                'submission_id' => null,
                'role' => $role,
                'participation_form' => ParticipationForm::Online->value,
                'status' => ParticipationStatus::Active->value,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            return ['participationId' => $id, 'created' => true];
        });
    }

    public function sessionPresence(int $editionId): array
    {
        $out = [];
        $rows = $this->db->table('conf_session_attendance as a')
            ->join('conf_sessions as s', 's.id', '=', 'a.session_id')
            ->where('s.edition_id', $editionId)
            ->orderBy('s.starts_at')->orderBy('s.id')
            ->get(['a.participation_id', 'a.session_id'])->all();
        foreach ($rows as $r) {
            $out[(int) $r->participation_id][] = (int) $r->session_id;
        }
        return $out;
    }

    public function ofSession(int $sessionId): array
    {
        $out = [];
        foreach ($this->db->table('conf_session_attendance')->where('session_id', $sessionId)->get()->all() as $r) {
            $out[(int) $r->participation_id] = [
                'minutes' => $r->minutes === null ? null : (int) $r->minutes,
                'source' => (string) $r->source,
                'markedAt' => (string) $r->marked_at,
            ];
        }
        return $out;
    }

    public function markSession(int $editionId, int $sessionId, array $participationIds, bool $attended, ?int $minutes, string $source, ?int $actorId, DateTimeImmutable $now): int
    {
        if ($participationIds === []) {
            return 0;
        }
        return $this->db->transaction(function () use ($editionId, $sessionId, $participationIds, $attended, $minutes, $source, $actorId, $now): int {
            if (!$this->db->table('conf_sessions')->where('edition_id', $editionId)->where('id', $sessionId)->exists()) {
                return 0;
            }
            $ids = array_map('intval', $this->db->table('conf_participations as pn')
                ->join('conf_participants as p', 'p.id', '=', 'pn.participant_id')
                ->where('p.edition_id', $editionId)->whereIn('pn.id', $participationIds)->pluck('pn.id')->all());
            if ($ids === []) {
                return 0;
            }
            $existing = [];
            foreach ($this->db->table('conf_session_attendance')->where('session_id', $sessionId)->whereIn('participation_id', $ids)->get(['participation_id', 'source'])->all() as $r) {
                $existing[(int) $r->participation_id] = (string) $r->source;
            }
            $byHand = 'manual';
            if (!$attended) {
                $q = $this->db->table('conf_session_attendance')->where('session_id', $sessionId)->whereIn('participation_id', $ids);
                if ($source !== $byHand) {
                    $q->where('source', '<>', $byHand);
                }
                return $q->delete();
            }
            $stamp = DateInput::toStorage($now);
            $changed = 0;
            foreach ($ids as $id) {
                $old = $existing[$id] ?? null;
                if ($old === $byHand && $source !== $byHand) {
                    continue;
                }
                $row = ['minutes' => $minutes, 'source' => $source, 'marked_by' => $actorId, 'marked_at' => $stamp];
                if ($old === null) {
                    $this->db->table('conf_session_attendance')->insert(['session_id' => $sessionId, 'participation_id' => $id] + $row);
                } elseif ($source === $byHand && $old === $byHand) {
                    continue;
                } else {
                    $this->db->table('conf_session_attendance')->where('session_id', $sessionId)->where('participation_id', $id)->update($row);
                }
                $changed++;
            }
            return $changed;
        });
    }
}
