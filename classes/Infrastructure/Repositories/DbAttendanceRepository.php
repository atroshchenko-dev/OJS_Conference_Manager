<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Attendance\AttendanceRepository;
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
}
