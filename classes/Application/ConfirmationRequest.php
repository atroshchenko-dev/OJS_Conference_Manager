<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use DateTimeImmutable;

/**
 * A letter to a co-author asking to confirm participation (REG-04).
 */
final class ConfirmationRequest
{
    /**
     * @param array<string, string> $editionTitle locale => title
     * @param array<string, string> $submissionTitle locale => title
     */
    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly string $token,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $timezone,
        public readonly array $editionTitle,
        public readonly array $submissionTitle,
        public readonly string $submitterName,
        public readonly string $role,
    ) {
    }
}
