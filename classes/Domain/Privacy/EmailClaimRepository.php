<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Privacy;

use DateTimeImmutable;

/** Confirmations that an account owns an email address (see EmailClaimService). */
interface EmailClaimRepository
{
    /** @return array{tokenHash: ?string, requestedAt: ?DateTimeImmutable, confirmedAt: ?DateTimeImmutable}|null */
    public function find(int $userId, string $email): ?array;

    public function saveRequest(int $userId, string $email, string $tokenHash, DateTimeImmutable $now): void;

    public function confirm(int $userId, string $email, DateTimeImmutable $now): void;

    /** Records of the journal for this address that belong to no account or to another one. */
    public function unlinkedCount(int $contextId, int $userId, string $email): int;

    /** Gives the account the records for this address that belong to no account. */
    public function link(int $userId, string $email): int;
}
