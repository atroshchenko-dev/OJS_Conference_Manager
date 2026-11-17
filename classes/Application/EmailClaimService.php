<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Privacy\EmailClaimRepository;
use DateInterval;
use DateTimeImmutable;

/**
 * Records of a participant can be made for an email address before the
 * person has an account (manager's invitation, attendance import, a
 * co-author of a submission). OJS does not confirm the address of a new
 * account by default, so such records are shown to an account only when
 * OJS has validated its address or its owner has confirmed the address
 * with a link sent to it (security audit 0.33).
 */
final class EmailClaimService
{
    public const TOKEN_DAYS = 7;
    /** A new letter at most this often, so the form cannot be used to flood a mailbox. */
    public const RESEND_MINUTES = 10;

    public function __construct(private readonly EmailClaimRepository $claims)
    {
    }

    /**
     * The address by which the account's records may be found, or '' when
     * only records already given to the account by its id may be shown.
     */
    public function matchEmail(int $userId, string $email, bool $validatedByOjs): string
    {
        $email = self::normalize($email);
        if ($email === '') {
            return '';
        }
        return $validatedByOjs || $this->isConfirmed($userId, $email) ? $email : '';
    }

    public function isConfirmed(int $userId, string $email): bool
    {
        return ($this->claims->find($userId, self::normalize($email))['confirmedAt'] ?? null) !== null;
    }

    /** The journal has records for this address that the account cannot see yet. */
    public function hasUnclaimed(ContextInfo $context, int $userId, string $email, bool $validatedByOjs): bool
    {
        $email = self::normalize($email);
        return $email !== '' && $this->matchEmail($userId, $email, $validatedByOjs) === ''
            && $this->claims->unlinkedCount($context->id, $userId, $email) > 0;
    }

    /**
     * A new confirmation token for the letter, or null when the address is
     * confirmed already or a letter was sent less than RESEND_MINUTES ago.
     */
    public function request(int $userId, string $email, DateTimeImmutable $now): ?string
    {
        $email = self::normalize($email);
        $row = $email === '' ? null : $this->claims->find($userId, $email);
        if ($email === '' || ($row['confirmedAt'] ?? null) !== null) {
            return null;
        }
        $last = $row['requestedAt'] ?? null;
        if ($last !== null && $last > $now->sub(new DateInterval('PT' . self::RESEND_MINUTES . 'M'))) {
            return null;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->claims->saveRequest($userId, $email, hash('sha256', $token), $now);
        return $token;
    }

    /**
     * The link from the letter, opened by the same account while its
     * address is still the one the letter went to. Gives the account the
     * records for the address.
     */
    public function confirm(int $userId, string $email, string $token, DateTimeImmutable $now): bool
    {
        $email = self::normalize($email);
        $row = $email === '' || $token === '' ? null : $this->claims->find($userId, $email);
        if ($row === null || $row['tokenHash'] === null || $row['requestedAt'] === null
            || $row['requestedAt'] < $now->sub(new DateInterval('P' . self::TOKEN_DAYS . 'D'))
            || !hash_equals($row['tokenHash'], hash('sha256', $token))) {
            return false;
        }
        $this->claims->confirm($userId, $email, $now);
        $this->claims->link($userId, $email);
        return true;
    }

    private static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
