<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Site;

/**
 * Load profiles of an installation (plan, section 15.2). The site
 * administrator picks one; background work of every journal follows it.
 */
enum LoadProfile: string
{
    /** Shared web hosting without cron. */
    case SharedHosting = 'shared_hosting';
    /** A server or VM of the institution (default). */
    case Standard = 'standard';
    /** A multi-institution installation with cron. */
    case Provider = 'provider';

    public static function fromSetting(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Standard) : self::Standard;
    }

    /** Records handled by one background job. */
    public function batchSize(): int
    {
        return $this === self::SharedHosting ? 10 : 30;
    }

    /** Recommended OJS `[queues] job_runner_max_jobs`; 0: run jobs by cron only (`job_runner = Off`). */
    public function jobsPerRequest(): int
    {
        return match ($this) {
            self::SharedHosting => 5,
            self::Standard => 30,
            self::Provider => 0,
        };
    }

    /** Letters of mailings per hour for the whole installation; null: as the SMTP server allows. */
    public function mailsPerHour(): ?int
    {
        return match ($this) {
            self::SharedHosting => 100,
            self::Standard => 500,
            self::Provider => null,
        };
    }

    /** Mass work runs only at night (22:00–07:00 server time). */
    public function nightOnly(): bool
    {
        return $this === self::SharedHosting;
    }

    public const NIGHT_FROM = 22;
    public const NIGHT_TO = 7;

    /**
     * The earliest moment mass work may run, or null when it may run now.
     */
    public function windowOpensAt(\DateTimeImmutable $now, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (!$this->nightOnly()) {
            return null;
        }
        $local = $now->setTimezone($zone);
        $hour = (int) $local->format('G');
        if ($hour >= self::NIGHT_FROM || $hour < self::NIGHT_TO) {
            return null;
        }
        return $local->setTime(self::NIGHT_FROM, 0)->setTimezone(new \DateTimeZone('UTC'));
    }
}
