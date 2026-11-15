<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Program;

/** PRG-02: a room of an edition, physical or online. */
final class Room
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $editionId,
        public readonly string $name,
        public readonly ?int $capacity = null,
        public readonly ?string $onlineUrl = null,
    ) {
    }

    public function withId(int $id): self
    {
        return new self($id, $this->editionId, $this->name, $this->capacity, $this->onlineUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'editionId' => $this->editionId, 'name' => $this->name, 'capacity' => $this->capacity, 'onlineUrl' => $this->onlineUrl];
    }
}
