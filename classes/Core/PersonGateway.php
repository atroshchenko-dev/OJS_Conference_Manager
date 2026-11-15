<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\facades\Repo;

/**
 * What the plugin takes from an OJS user account when the person registers
 * as a listener (REG-05): the account itself stays the source of truth
 * for the name and email.
 */
final class PersonGateway
{
    /**
     * @return array{userId: int, email: string, givenName: array<string, string>, familyName: array<string, string>,
     *   affiliation: ?string, country: ?string, orcid: ?string, position: ?string}|null
     */
    public function of(int $userId, string $locale, ?string $position = null, ?string $affiliation = null): ?array
    {
        $user = Repo::user()->get($userId);
        if ($user === null) {
            return null;
        }
        $strings = static fn (mixed $v): array => array_filter(
            array_map(static fn (mixed $s): string => is_string($s) ? $s : '', is_array($v) ? $v : []),
            static fn (string $s): bool => trim($s) !== '',
        );
        $own = $strings($user->getData('affiliation'));
        $affiliation = $affiliation !== null && trim($affiliation) !== '' ? $affiliation : ($own[$locale] ?? (array_values($own)[0] ?? null));
        $orcid = $user->getData('orcid');
        $country = $user->getData('country');
        return [
            'userId' => $userId,
            'email' => (string) $user->getEmail(),
            'givenName' => $strings($user->getData('givenName')),
            'familyName' => $strings($user->getData('familyName')),
            'affiliation' => $affiliation,
            'country' => is_string($country) && $country !== '' ? $country : null,
            'orcid' => is_string($orcid) && $orcid !== '' ? $orcid : null,
            'position' => $position,
        ];
    }
}
