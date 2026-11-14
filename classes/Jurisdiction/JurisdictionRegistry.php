<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Jurisdiction;

use APP\plugins\generic\conferenceManager\classes\Jurisdiction\Generic\GenericProfile;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\UA\UaProfile;
use InvalidArgumentException;

final class JurisdictionRegistry
{
    /** @var array<string, JurisdictionProfile> */
    private array $profiles = [];

    public static function withBuiltIns(): self
    {
        $registry = new self();
        $registry->register(new GenericProfile());
        $registry->register(new UaProfile());
        return $registry;
    }

    public function register(JurisdictionProfile $profile): void
    {
        $this->profiles[$profile->getCode()] = $profile;
    }

    public function has(string $code): bool
    {
        return isset($this->profiles[$code]);
    }

    public function get(string $code): JurisdictionProfile
    {
        return $this->profiles[$code] ?? throw new InvalidArgumentException("Unknown jurisdiction profile: {$code}");
    }

    /** @return array<string, JurisdictionProfile> */
    public function all(): array
    {
        return $this->profiles;
    }

    /**
     * Default profile for a new context: UA for a Ukrainian primary locale,
     * generic otherwise (INS-06).
     */
    public function defaultCodeForLocale(string $primaryLocale): string
    {
        if (str_starts_with($primaryLocale, 'uk') && $this->has(UaProfile::CODE)) {
            return UaProfile::CODE;
        }
        return GenericProfile::CODE;
    }
}
