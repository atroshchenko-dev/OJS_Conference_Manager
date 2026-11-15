<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Attendance;

/**
 * ATT-04: the code of a personal entry ticket, "{participationId}-{mac}".
 * The MAC is an HMAC of the participation id with a secret of the site,
 * so a ticket cannot be guessed from another one and needs no storage.
 */
final class CheckinToken
{
    private const MAC_LENGTH = 20;

    public static function make(int $participationId, string $secret): string
    {
        return $participationId . '-' . self::mac($participationId, $secret);
    }

    /** The participation id, or null when the code is not genuine. */
    public static function read(string $token, string $secret): ?int
    {
        if (!preg_match('/^([1-9]\d{0,17})-([0-9a-f]{' . self::MAC_LENGTH . '})$/', $token, $m)) {
            return null;
        }
        $id = (int) $m[1];
        return $secret !== '' && hash_equals(self::mac($id, $secret), $m[2]) ? $id : null;
    }

    private static function mac(int $participationId, string $secret): string
    {
        return substr(hash_hmac('sha256', 'conferenceManager:checkin:' . $participationId, $secret), 0, self::MAC_LENGTH);
    }
}
