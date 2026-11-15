<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Review;

/**
 * How an edition is reviewed:
 *  - DEC-07: the review forms of the two rounds in the two-phase mode
 *    (round 1 reviews the abstract, the next rounds the paper); a form
 *    chosen by the editor for a reviewer stays as it is;
 *  - DEC-10: until which day reviewers say what they would like to review
 *    (a date in the edition's time zone, included) and how many reviewers
 *    the distribution gives each submission.
 */
final class ReviewSettings
{
    public const DEFAULT_PER_SUBMISSION = 2;
    public const MAX_PER_SUBMISSION = 10;

    public function __construct(
        public readonly ?int $abstractFormId = null,
        public readonly ?int $paperFormId = null,
        public readonly ?string $biddingUntil = null,
        public readonly int $reviewersPerSubmission = self::DEFAULT_PER_SUBMISSION,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = static fn (mixed $v): ?int => is_numeric($v) && (int) $v > 0 ? (int) $v : null;
        $until = trim((string) ($data['biddingUntil'] ?? ''));
        $per = filter_var($data['reviewersPerSubmission'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PER_SUBMISSION]]);
        return new self(
            $id($data['abstractFormId'] ?? null),
            $id($data['paperFormId'] ?? null),
            self::isDate($until) ? $until : null,
            $per === false ? self::DEFAULT_PER_SUBMISSION : $per,
        );
    }

    public static function isDate(string $s): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s;
    }

    /** @return array{abstractFormId: ?int, paperFormId: ?int, biddingUntil: ?string, reviewersPerSubmission: int} */
    public function toArray(): array
    {
        return [
            'abstractFormId' => $this->abstractFormId,
            'paperFormId' => $this->paperFormId,
            'biddingUntil' => $this->biddingUntil,
            'reviewersPerSubmission' => $this->reviewersPerSubmission,
        ];
    }

    /** The form for a reviewer of the given round, or null to leave it as it is. */
    public function formForRound(int $round): ?int
    {
        return $round <= 1 ? $this->abstractFormId : $this->paperFormId;
    }

    /** DEC-10: reviewers may still bid on this day (Y-m-d in the edition's zone). */
    public function biddingOpenOn(string $day): bool
    {
        return $this->biddingUntil !== null && $day <= $this->biddingUntil;
    }
}
