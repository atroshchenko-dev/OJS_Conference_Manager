<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Review;

/**
 * DEC-07: the review forms of the two rounds of an edition in the
 * two-phase mode: round 1 reviews the abstract, the next rounds the paper.
 * A form chosen by the editor for a reviewer stays as it is.
 */
final class ReviewSettings
{
    public function __construct(
        public readonly ?int $abstractFormId = null,
        public readonly ?int $paperFormId = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = static fn (mixed $v): ?int => is_numeric($v) && (int) $v > 0 ? (int) $v : null;
        return new self($id($data['abstractFormId'] ?? null), $id($data['paperFormId'] ?? null));
    }

    /** @return array{abstractFormId: ?int, paperFormId: ?int} */
    public function toArray(): array
    {
        return ['abstractFormId' => $this->abstractFormId, 'paperFormId' => $this->paperFormId];
    }

    /** The form for a reviewer of the given round, or null to leave it as it is. */
    public function formForRound(int $round): ?int
    {
        return $round <= 1 ? $this->abstractFormId : $this->paperFormId;
    }
}
