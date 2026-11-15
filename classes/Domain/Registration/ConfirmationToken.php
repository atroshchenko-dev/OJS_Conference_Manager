<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Registration;

/**
 * One-time links for co-authors (REG-04). Only the SHA-256 of a token is
 * stored, so a database leak does not reveal working links.
 */
final class ConfirmationToken
{
    public const LIFETIME_DAYS = 30;

    /** @return array{0: string, 1: string} [token for the link, hash to store] */
    public static function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return [$token, self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function looksValid(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }
}
