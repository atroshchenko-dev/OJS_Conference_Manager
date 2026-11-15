<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Program;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\DateInput;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use DateTimeImmutable;

/**
 * PRG-02: a session of the program: time (UTC), room, chairs and the
 * ordered papers. A seminar may carry hours and learning outcomes (PRG-07).
 */
final class ProgramSession
{
    public const DEFAULT_SLOT = 15;

    /** Localized texts kept in conf_session_settings. */
    public const LOCALIZED_FIELDS = ['title', 'description', 'learningOutcomes'];

    /**
     * @param array<string, LocalizedText> $texts
     * @param list<int> $chairs participant ids
     * @param list<SessionItem> $items ordered by seq
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $editionId,
        public readonly SessionType $type,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly ?int $roomId = null,
        public readonly array $texts = [],
        public readonly int $slotMinutes = self::DEFAULT_SLOT,
        public readonly ?string $streamUrl = null,
        public readonly ?DateTimeImmutable $streamVisibleFrom = null,
        public readonly ?int $hours = null,
        public readonly ?int $ectsX100 = null,
        public readonly array $chairs = [],
        public readonly array $items = [],
    ) {
    }

    public function text(string $field): LocalizedText
    {
        return $this->texts[$field] ?? new LocalizedText();
    }

    public function minutes(): int
    {
        return intdiv($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp(), 60);
    }

    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }

    /**
     * Start and end of each paper: one after another from the start of
     * the session, each for its own duration or the slot of the session.
     *
     * @return array<int, array{0: DateTimeImmutable, 1: DateTimeImmutable}> submission id => [start, end]
     */
    public function itemTimes(): array
    {
        $out = [];
        $at = $this->startsAt;
        foreach ($this->items as $item) {
            $end = $at->modify('+' . ($item->durationMin ?? $this->slotMinutes) . ' minutes');
            $out[$item->submissionId] = [$at, $end];
            $at = $end;
        }
        return $out;
    }

    /** Minutes the papers take beyond the end of the session (0 when they fit). */
    public function overrun(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->durationMin ?? $this->slotMinutes;
        }
        return max(0, $total - $this->minutes());
    }

    /** @param list<SessionItem> $items */
    public function withItems(array $items): self
    {
        return new self($this->id, $this->editionId, $this->type, $this->startsAt, $this->endsAt, $this->roomId, $this->texts, $this->slotMinutes, $this->streamUrl, $this->streamVisibleFrom, $this->hours, $this->ectsX100, $this->chairs, $items);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->editionId, $this->type, $this->startsAt, $this->endsAt, $this->roomId, $this->texts, $this->slotMinutes, $this->streamUrl, $this->streamVisibleFrom, $this->hours, $this->ectsX100, $this->chairs, $this->items);
    }

    /** @return array<string, mixed> times in UTC and in the zone of the edition */
    public function toArray(string $timezone): array
    {
        $out = [
            'id' => $this->id,
            'editionId' => $this->editionId,
            'type' => $this->type->value,
            'roomId' => $this->roomId,
            'startsAt' => DateInput::toIsoUtc($this->startsAt),
            'endsAt' => DateInput::toIsoUtc($this->endsAt),
            'local' => [
                'startsAt' => DateInput::toLocal($this->startsAt, $timezone),
                'endsAt' => DateInput::toLocal($this->endsAt, $timezone),
                'streamVisibleFrom' => DateInput::toLocal($this->streamVisibleFrom, $timezone),
            ],
            'slotMinutes' => $this->slotMinutes,
            'streamUrl' => $this->streamUrl,
            'streamVisibleFrom' => DateInput::toIsoUtc($this->streamVisibleFrom),
            'hours' => $this->hours,
            'ectsX100' => $this->ectsX100,
            'chairs' => $this->chairs,
            'items' => array_map(static fn (SessionItem $i): array => ['submissionId' => $i->submissionId, 'durationMin' => $i->durationMin], $this->items),
        ];
        foreach (self::LOCALIZED_FIELDS as $field) {
            $out[$field] = $this->text($field)->toArray();
        }
        return $out;
    }
}
