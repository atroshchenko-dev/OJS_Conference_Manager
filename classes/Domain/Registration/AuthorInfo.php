<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

/**
 * An author of a submission as the plugin needs it (filled by Core).
 */
final class AuthorInfo
{
    /**
     * @param array<string, string> $givenName locale => text
     * @param array<string, string> $familyName locale => text
     */
    public function __construct(
        public readonly int $authorId,
        public readonly string $email,
        public readonly array $givenName,
        public readonly array $familyName = [],
        public readonly ?string $affiliation = null,
        public readonly ?string $orcid = null,
        public readonly ?string $country = null,
        public readonly ?int $userId = null,
    ) {
    }

    public function normalizedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }

    public function displayName(string $locale): string
    {
        $given = $this->givenName[$locale] ?? (array_values($this->givenName)[0] ?? '');
        $family = $this->familyName[$locale] ?? (array_values($this->familyName)[0] ?? '');
        return trim($given . ' ' . $family);
    }
}
