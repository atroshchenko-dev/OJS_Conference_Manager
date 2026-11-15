<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories;

use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramSession;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\Room;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\SessionItem;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\SessionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use Illuminate\Database\Connection;

/** PRG-02, PRG-03 on conf_rooms, conf_sessions, conf_session_settings, conf_session_chairs, conf_session_items. */
final class DbProgramRepository implements ProgramRepository
{
    private const SETTING_SLOT = 'slotMinutes';

    public function __construct(private readonly Connection $db)
    {
    }

    public function rooms(int $editionId): array
    {
        return array_map(static fn (object $r): Room => new Room(
            (int) $r->id,
            (int) $r->edition_id,
            (string) $r->name,
            $r->capacity === null ? null : (int) $r->capacity,
            $r->online_url === null || $r->online_url === '' ? null : (string) $r->online_url,
        ), $this->db->table('conf_rooms')->where('edition_id', $editionId)->orderBy('name')->orderBy('id')->get()->all());
    }

    public function saveRoom(Room $room): Room
    {
        $row = ['edition_id' => $room->editionId, 'name' => $room->name, 'capacity' => $room->capacity, 'online_url' => $room->onlineUrl, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($room->id !== null) {
            $this->db->table('conf_rooms')->where('edition_id', $room->editionId)->where('id', $room->id)->update($row);
            return $room;
        }
        $row['created_at'] = $row['updated_at'];
        return $room->withId((int) $this->db->table('conf_rooms')->insertGetId($row));
    }

    public function deleteRoom(int $editionId, int $id): bool
    {
        return $this->db->transaction(function () use ($editionId, $id): bool {
            // SQLite in tests does not run "on delete set null" without the pragma.
            $this->db->table('conf_sessions')->where('edition_id', $editionId)->where('room_id', $id)->update(['room_id' => null]);
            return $this->db->table('conf_rooms')->where('edition_id', $editionId)->where('id', $id)->delete() > 0;
        });
    }

    public function sessions(int $editionId): array
    {
        $rows = $this->db->table('conf_sessions')->where('edition_id', $editionId)->orderBy('starts_at')->orderBy('id')->get()->all();
        return $this->hydrate($rows);
    }

    public function findSession(int $editionId, int $id): ?ProgramSession
    {
        $rows = $this->db->table('conf_sessions')->where('edition_id', $editionId)->where('id', $id)->get()->all();
        return $this->hydrate($rows)[0] ?? null;
    }

    public function saveSession(ProgramSession $s): ProgramSession
    {
        return $this->db->transaction(function () use ($s): ProgramSession {
            $row = [
                'edition_id' => $s->editionId,
                'room_id' => $s->roomId,
                'type' => $s->type->value,
                'starts_at' => DateInput::toStorage($s->startsAt),
                'ends_at' => DateInput::toStorage($s->endsAt),
                'stream_url' => $s->streamUrl,
                'stream_visible_from' => DateInput::toStorage($s->streamVisibleFrom),
                'hours' => $s->hours,
                'ects_x100' => $s->ectsX100,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            $id = $s->id;
            if ($id !== null) {
                $this->db->table('conf_sessions')->where('edition_id', $s->editionId)->where('id', $id)->update($row);
            } else {
                $row['created_at'] = $row['updated_at'];
                $id = (int) $this->db->table('conf_sessions')->insertGetId($row);
            }
            $this->db->table('conf_session_settings')->where('session_id', $id)->delete();
            $settings = [['session_id' => $id, 'locale' => '', 'setting_name' => self::SETTING_SLOT, 'setting_value' => (string) $s->slotMinutes]];
            foreach (ProgramSession::LOCALIZED_FIELDS as $field) {
                foreach ($s->text($field)->toArray() as $locale => $value) {
                    $settings[] = ['session_id' => $id, 'locale' => $locale, 'setting_name' => $field, 'setting_value' => $value];
                }
            }
            $this->db->table('conf_session_settings')->insert($settings);
            $this->db->table('conf_session_chairs')->where('session_id', $id)->delete();
            $now = gmdate('Y-m-d H:i:s');
            $chairs = array_map(static fn (int $p): array => ['session_id' => $id, 'participant_id' => $p, 'created_at' => $now, 'updated_at' => $now], array_values(array_unique($s->chairs)));
            if ($chairs !== []) {
                $this->db->table('conf_session_chairs')->insert($chairs);
            }
            return $s->withId($id);
        });
    }

    public function deleteSession(int $editionId, int $id): bool
    {
        return $this->db->transaction(function () use ($editionId, $id): bool {
            if (!$this->db->table('conf_sessions')->where('edition_id', $editionId)->where('id', $id)->exists()) {
                return false;
            }
            foreach (['conf_session_items', 'conf_session_chairs', 'conf_session_settings', 'conf_session_attendance'] as $table) {
                $this->db->table($table)->where('session_id', $id)->delete();
            }
            return $this->db->table('conf_sessions')->where('id', $id)->delete() > 0;
        });
    }

    public function placeItem(int $editionId, int $submissionId, ?int $sessionId, int $position, ?int $durationMin): void
    {
        $this->db->transaction(function () use ($editionId, $submissionId, $sessionId, $position, $durationMin): void {
            $sessionIds = $this->db->table('conf_sessions')->where('edition_id', $editionId)->pluck('id')->all();
            if ($sessionIds === []) {
                return;
            }
            $old = $this->db->table('conf_session_items')->whereIn('session_id', $sessionIds)->where('submission_id', $submissionId)->first();
            $this->db->table('conf_session_items')->whereIn('session_id', $sessionIds)->where('submission_id', $submissionId)->delete();
            if ($old !== null) {
                $this->renumber((int) $old->session_id);
            }
            if ($sessionId === null) {
                return;
            }
            $ids = $this->db->table('conf_session_items')->where('session_id', $sessionId)->orderBy('seq')->orderBy('id')->pluck('submission_id')->all();
            $position = max(0, min($position, count($ids)));
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('conf_session_items')->insert([
                'session_id' => $sessionId, 'submission_id' => $submissionId, 'seq' => $position, 'created_at' => $now, 'updated_at' => $now,
                'duration_min' => $durationMin ?? ($old !== null && $old->duration_min !== null ? (int) $old->duration_min : null),
            ]);
            foreach ($ids as $i => $other) {
                $this->db->table('conf_session_items')->where('session_id', $sessionId)->where('submission_id', $other)->update(['seq' => $i < $position ? $i : $i + 1]);
            }
        });
    }

    private function renumber(int $sessionId): void
    {
        $rows = $this->db->table('conf_session_items')->where('session_id', $sessionId)->orderBy('seq')->orderBy('id')->get(['id'])->all();
        foreach ($rows as $i => $r) {
            $this->db->table('conf_session_items')->where('id', $r->id)->update(['seq' => $i]);
        }
    }

    public function presenters(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('conf_participations')->whereIn('submission_id', $submissionIds)->where('role', 'presenter')
            ->where('status', 'active')->get(['submission_id', 'participant_id'])->all() as $r) {
            $out[(int) $r->submission_id][] = (int) $r->participant_id;
        }
        return $out;
    }

    public function people(int $editionId, string $locale): array
    {
        $ids = array_map('intval', $this->db->table('conf_participants')->where('edition_id', $editionId)->pluck('id')->all());
        $names = (new DbParticipantRepository($this->db))->names($ids, $locale);
        $out = [];
        foreach ($ids as $id) {
            $out[] = ['id' => $id, 'name' => ($names[$id] ?? '') !== '' ? $names[$id] : '#' . $id];
        }
        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;
        usort($out, static fn (array $a, array $b): int => $collator === null ? strcmp($a['name'], $b['name']) : (int) $collator->compare($a['name'], $b['name']));
        return $out;
    }

    public function chairedBy(array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('conf_session_chairs as c')->join('conf_sessions as s', 's.id', '=', 'c.session_id')
            ->whereIn('c.participant_id', $participantIds)->orderBy('s.starts_at')->orderBy('s.id')->get(['s.id', 's.edition_id'])->all() as $r) {
            $out[(int) $r->id] = (int) $r->edition_id;
        }
        return $out;
    }

    /**
     * @param list<object> $rows
     * @return list<ProgramSession>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (object $r): int => (int) $r->id, $rows);
        $texts = [];
        $slots = [];
        foreach ($this->db->table('conf_session_settings')->whereIn('session_id', $ids)->get()->all() as $s) {
            if ($s->setting_name === self::SETTING_SLOT) {
                $slots[(int) $s->session_id] = (int) $s->setting_value;
            } else {
                $texts[(int) $s->session_id][(string) $s->setting_name][(string) $s->locale] = (string) $s->setting_value;
            }
        }
        $chairs = [];
        foreach ($this->db->table('conf_session_chairs')->whereIn('session_id', $ids)->orderBy('id')->get()->all() as $c) {
            $chairs[(int) $c->session_id][] = (int) $c->participant_id;
        }
        $items = [];
        foreach ($this->db->table('conf_session_items')->whereIn('session_id', $ids)->whereNotNull('submission_id')->orderBy('seq')->orderBy('id')->get()->all() as $i) {
            $items[(int) $i->session_id][] = new SessionItem((int) $i->submission_id, (int) $i->seq, $i->duration_min === null ? null : (int) $i->duration_min);
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $localized = [];
            foreach (ProgramSession::LOCALIZED_FIELDS as $field) {
                $localized[$field] = new LocalizedText($texts[$id][$field] ?? []);
            }
            $out[] = new ProgramSession(
                $id,
                (int) $r->edition_id,
                SessionType::tryFrom((string) $r->type) ?? SessionType::Section,
                DateInput::fromStorage((string) $r->starts_at) ?? new \DateTimeImmutable('@0'),
                DateInput::fromStorage((string) $r->ends_at) ?? new \DateTimeImmutable('@0'),
                $r->room_id === null ? null : (int) $r->room_id,
                $localized,
                $slots[$id] ?? ProgramSession::DEFAULT_SLOT,
                $r->stream_url === null || $r->stream_url === '' ? null : (string) $r->stream_url,
                DateInput::fromStorage($r->stream_visible_from === null ? null : (string) $r->stream_visible_from),
                $r->hours === null ? null : (int) $r->hours,
                $r->ects_x100 === null ? null : (int) $r->ects_x100,
                $chairs[$id] ?? [],
                $items[$id] ?? [],
            );
        }
        return $out;
    }
}
