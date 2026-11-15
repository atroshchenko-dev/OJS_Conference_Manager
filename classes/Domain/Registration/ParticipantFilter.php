<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

/**
 * Filters of the register of participants (REG-07).
 */
final class ParticipantFilter
{
    public const MAX_PAGE = 50;

    public function __construct(
        public readonly ?ParticipantRole $role = null,
        /** a category id, 0 for "no category", null for any */
        public readonly ?int $categoryId = null,
        public readonly ?ParticipationStatus $status = null,
        public readonly ?ConfirmationStatus $confirmation = null,
        public readonly string $search = '',
        public readonly int $count = 20,
        public readonly int $offset = 0,
        public readonly ?PaymentFilter $payment = null,
    ) {
    }

    public function limit(): int
    {
        return max(1, min(self::MAX_PAGE, $this->count));
    }
}
