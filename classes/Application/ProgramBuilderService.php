<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\ProgramSession;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\Room;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\Schedule;
use APP\plugins\generic\conferenceManager\classes\Domain\Program\SessionType;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;

/**
 * The program builder (PRG-02, PRG-03): rooms, sessions with chairs and
 * the order of accepted papers in them, with the checks a committee needs
 * while moving papers around: a presenter or chair in two sessions at the
 * same time, two sessions in one room, papers that do not fit.
 */
final class ProgramBuilderService
{
    private const K = 'plugins.generic.conferenceManager.program.error.';
    private const V = 'plugins.generic.conferenceManager.validation.';

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly ProgramRepository $program,
        private readonly ProgramService $papers,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Everything the builder shows.
     *
     * @return array<string, mixed>|null
     */
    public function overview(ContextInfo $context, int $editionId, string $locale): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        $papers = $this->papers->papers($context, $editionId, $locale);
        if ($edition === null || $papers === null) {
            return null;
        }
        $byId = [];
        foreach ($papers as $p) {
            $byId[$p['submissionId']] = $p;
        }
        $sessions = $this->program->sessions($editionId);
        $placed = [];
        foreach ($sessions as $s) {
            foreach ($s->items as $i) {
                $placed[$i->submissionId] = true;
            }
        }
        $presenters = $this->program->presenters(array_map('intval', array_keys($placed)));
        $conflicts = Schedule::personConflicts($sessions, $presenters);
        $people = $this->program->people($editionId, $locale);
        $names = array_column($people, 'name', 'id');
        $inConflict = [];
        foreach ($conflicts as $c) {
            foreach ($c['sessionIds'] as $sid) {
                $inConflict[$sid][] = $names[$c['participantId']] ?? '#' . $c['participantId'];
            }
        }

        $tz = $edition->timezone;
        $outSessions = [];
        foreach ($sessions as $s) {
            $times = $s->itemTimes();
            $items = [];
            foreach ($s->items as $i) {
                $paper = $byId[$i->submissionId] ?? null;
                $items[] = [
                    'submissionId' => $i->submissionId,
                    'durationMin' => $i->durationMin,
                    'startsAt' => DateInput::toLocal($times[$i->submissionId][0], $tz),
                    'endsAt' => DateInput::toLocal($times[$i->submissionId][1], $tz),
                    'title' => $paper['title'] ?? '',
                    'authors' => $paper['authors'] ?? '',
                    'type' => $paper['type'] ?? null,
                    // A paper withdrawn or declined after it was placed.
                    'accepted' => $paper !== null,
                    'conflict' => array_values(array_intersect($presenters[$i->submissionId] ?? [], array_column(array_filter($conflicts, static fn (array $c): bool => in_array((int) $s->id, $c['sessionIds'], true)), 'participantId'))) !== [],
                ];
            }
            $outSessions[] = $s->toArray($tz) + [
                'paperItems' => $items,
                'overrun' => $s->overrun(),
                'roomClashes' => Schedule::roomClashes($s, $sessions),
                'conflicts' => array_values(array_unique($inConflict[(int) $s->id] ?? [])),
            ];
        }

        return [
            'timezone' => $tz,
            'edition' => ['startsAt' => DateInput::toLocal($edition->date('startsAt'), $tz), 'endsAt' => DateInput::toLocal($edition->date('endsAt'), $tz)],
            'rooms' => array_map(static fn (Room $r): array => $r->toArray(), $this->program->rooms($editionId)),
            'sessions' => $outSessions,
            'unassigned' => array_values(array_filter($papers, static fn (array $p): bool => !isset($placed[$p['submissionId']]))),
            'people' => $people,
            'conflicts' => array_map(static fn (array $c): array => $c + ['name' => $names[$c['participantId']] ?? '#' . $c['participantId']], $conflicts),
        ];
    }

    /**
     * @param array<string, mixed> $input name, capacity, onlineUrl
     * @return array{0: ?Room, 1: ValidationErrors}|null null when the edition or room is not found
     */
    public function saveRoom(ContextInfo $context, int $editionId, ?int $id, array $input, ?int $actorId = null): ?array
    {
        if (!$this->owns($context, $editionId) || ($id !== null && !in_array($id, array_map(static fn (Room $r): ?int => $r->id, $this->program->rooms($editionId)), true))) {
            return null;
        }
        $errors = new ValidationErrors();
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 255);
        if ($name === '') {
            $errors->add('name', self::V . 'required');
        }
        $capacity = ($input['capacity'] ?? '') === '' || $input['capacity'] === null ? null : filter_var($input['capacity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        if ($capacity === false) {
            $errors->add('capacity', self::K . 'capacity');
            $capacity = null;
        }
        $url = self::url($input['onlineUrl'] ?? null, 'onlineUrl', $errors);
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $saved = $this->program->saveRoom(new Room($id, $editionId, $name, $capacity, $url));
        $this->audit?->log($context->id, $actorId, 'program_room', $saved->id, $id === null ? 'create' : 'update', null, $saved->toArray());
        return [$saved, $errors];
    }

    public function deleteRoom(ContextInfo $context, int $editionId, int $id, ?int $actorId = null): bool
    {
        if (!$this->owns($context, $editionId) || !$this->program->deleteRoom($editionId, $id)) {
            return false;
        }
        $this->audit?->log($context->id, $actorId, 'program_room', $id, 'delete');
        return true;
    }

    /**
     * @param array<string, mixed> $input type, roomId, startsAt, endsAt (local time of the edition),
     *   slotMinutes, streamUrl, streamVisibleFrom, hours, ectsX100, chairs[], title{}, description{}, learningOutcomes{}
     * @return array{0: ?ProgramSession, 1: ValidationErrors}|null
     */
    public function saveSession(ContextInfo $context, int $editionId, ?int $id, array $input, ?int $actorId = null): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        $current = $id === null ? null : $this->program->findSession($editionId, $id);
        if ($edition === null || ($id !== null && $current === null)) {
            return null;
        }
        $errors = new ValidationErrors();
        $type = SessionType::tryFrom((string) ($input['type'] ?? '')) ?? SessionType::Section;
        $roomId = ($input['roomId'] ?? null) === null || $input['roomId'] === '' || (int) $input['roomId'] === 0 ? null : (int) $input['roomId'];
        if ($roomId !== null && !in_array($roomId, array_map(static fn (Room $r): ?int => $r->id, $this->program->rooms($editionId)), true)) {
            $errors->add('roomId', self::K . 'room');
        }
        $starts = DateInput::parse($input['startsAt'] ?? null, $edition->timezone);
        $ends = DateInput::parse($input['endsAt'] ?? null, $edition->timezone);
        if (!$starts instanceof \DateTimeImmutable) {
            $errors->add('startsAt', self::V . ($starts === false ? 'date' : 'required'));
        }
        if (!$ends instanceof \DateTimeImmutable) {
            $errors->add('endsAt', self::V . ($ends === false ? 'date' : 'required'));
        }
        if ($starts instanceof \DateTimeImmutable && $ends instanceof \DateTimeImmutable && $ends <= $starts) {
            $errors->add('endsAt', self::V . 'dateOrder');
        }
        $visible = DateInput::parse($input['streamVisibleFrom'] ?? null, $edition->timezone);
        if ($visible === false) {
            $errors->add('streamVisibleFrom', self::V . 'date');
            $visible = null;
        }
        $slot = filter_var($input['slotMinutes'] ?? ProgramSession::DEFAULT_SLOT, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 480]]);
        if ($slot === false) {
            $errors->add('slotMinutes', self::K . 'slot');
            $slot = ProgramSession::DEFAULT_SLOT;
        }
        $hours = self::number($input['hours'] ?? null, 'hours', 1000, $errors);
        $ects = self::number($input['ectsX100'] ?? null, 'ectsX100', 100000, $errors);
        $stream = self::url($input['streamUrl'] ?? null, 'streamUrl', $errors);
        $texts = [];
        foreach (ProgramSession::LOCALIZED_FIELDS as $field) {
            $values = [];
            foreach ((array) ($input[$field] ?? []) as $locale => $value) {
                if (in_array($locale, $context->formLocales, true) && trim((string) $value) !== '') {
                    $values[$locale] = mb_substr(trim((string) $value), 0, $field === 'title' ? 500 : 10000);
                }
            }
            $texts[$field] = new LocalizedText($values);
        }
        if ($texts['title']->get($context->primaryLocale) === null) {
            $errors->add('title', self::V . 'requiredInPrimaryLocale');
        }
        $people = array_column($this->program->people($editionId, $context->primaryLocale), 'id');
        $chairs = array_values(array_unique(array_map('intval', (array) ($input['chairs'] ?? []))));
        if (array_diff($chairs, $people) !== []) {
            $errors->add('chairs', self::K . 'chair');
        }
        if (!$errors->isEmpty() || !$starts instanceof \DateTimeImmutable || !$ends instanceof \DateTimeImmutable) {
            return [null, $errors];
        }
        $session = new ProgramSession(
            $id,
            $editionId,
            $type,
            $starts,
            $ends,
            $roomId,
            $texts,
            $slot,
            $stream,
            $visible,
            $type === SessionType::Seminar ? $hours : null,
            $type === SessionType::Seminar ? $ects : null,
            $chairs,
            $type->hasPapers() ? ($current->items ?? []) : [],
        );
        if (Schedule::roomClashes($session, $this->program->sessions($editionId)) !== []) {
            $errors->add('roomId', self::K . 'roomBusy');
            return [null, $errors];
        }
        if (!$type->hasPapers()) {
            foreach ($current->items ?? [] as $item) {
                $this->program->placeItem($editionId, $item->submissionId, null, 0, null);
            }
        }
        $saved = $this->program->saveSession($session);
        $this->audit?->log($context->id, $actorId, 'program_session', $saved->id, $id === null ? 'create' : 'update', $current?->toArray($edition->timezone), $saved->toArray($edition->timezone));
        return [$saved, $errors];
    }

    public function deleteSession(ContextInfo $context, int $editionId, int $id, ?int $actorId = null): bool
    {
        if (!$this->owns($context, $editionId) || !$this->program->deleteSession($editionId, $id)) {
            return false;
        }
        $this->audit?->log($context->id, $actorId, 'program_session', $id, 'delete');
        return true;
    }

    /**
     * PRG-03: moves an accepted paper to a position of a session (null takes
     * it out of the program). A given duration replaces the slot length.
     *
     * @return ValidationErrors|null null when the edition is not found
     */
    public function move(ContextInfo $context, int $editionId, int $submissionId, ?int $sessionId, int $position, mixed $durationMin = null, ?int $actorId = null): ?ValidationErrors
    {
        $papers = $this->papers->papers($context, $editionId, $context->primaryLocale);
        if ($papers === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $placedNow = false;
        foreach ($this->program->sessions($editionId) as $s) {
            foreach ($s->items as $i) {
                $placedNow = $placedNow || $i->submissionId === $submissionId;
            }
        }
        // A paper that is no longer accepted may still be taken out.
        if (!in_array($submissionId, array_column($papers, 'submissionId'), true) && !($sessionId === null && $placedNow)) {
            $errors->add('submissionId', self::K . 'paper');
            return $errors;
        }
        $session = $sessionId === null ? null : $this->program->findSession($editionId, $sessionId);
        if ($sessionId !== null && ($session === null || !$session->type->hasPapers())) {
            $errors->add('sessionId', self::K . 'session');
            return $errors;
        }
        $duration = $durationMin === null || $durationMin === '' ? null : filter_var($durationMin, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 480]]);
        if ($duration === false) {
            $errors->add('durationMin', self::K . 'slot');
            return $errors;
        }
        $this->program->placeItem($editionId, $submissionId, $sessionId, $position, $duration);
        $this->audit?->log($context->id, $actorId, 'program_session', $sessionId, 'move', null, ['submissionId' => $submissionId, 'position' => $position, 'durationMin' => $duration]);
        return $errors;
    }

    private function owns(ContextInfo $context, int $editionId): bool
    {
        return $this->editions->find($context->id, $editionId) instanceof Edition;
    }

    private static function url(mixed $value, string $field, ValidationErrors $errors): ?string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false || strlen($url) > 2048) {
            $errors->add($field, self::V . 'url');
            return null;
        }
        return $url;
    }

    private static function number(mixed $value, string $field, int $max, ValidationErrors $errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);
        if ($n === false) {
            $errors->add($field, self::V . 'range');
            return null;
        }
        return $n;
    }
}
