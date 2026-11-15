<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Domain\Site\LoadProfile;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Plan, sections 15.1 and 15.2: how much background work of the journals
 * runs and when. Mass work (mailings, batch certificates, bulk decisions)
 * waits for the night on shared hosting, pauses 48 hours before a
 * submission deadline of the journal, and mailings keep to the letters per
 * hour of the installation, shared fairly between journals. Reminders are
 * not mass work and are never held.
 */
final class LoadPolicy
{
    /** Site-level settings live under context id 0. */
    public const SITE = 0;
    public const QUIET_HOURS = 48;
    public const MAX_MAILS_PER_HOUR = 100000;
    private const K = 'plugins.generic.conferenceManager.site.';

    public function __construct(
        private readonly ?SettingsStore $settings,
        private readonly LoadStatistics $stats,
        private readonly DateTimeZone $serverZone,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    public function profile(): LoadProfile
    {
        return LoadProfile::fromSetting($this->settings?->get(self::SITE, 'loadProfile'));
    }

    /** Letters per hour: the administrator's number, or the profile's. */
    public function mailsPerHour(): ?int
    {
        $v = $this->settings?->get(self::SITE, 'mailsPerHour');
        return is_numeric($v) && (int) $v > 0 ? (int) $v : $this->profile()->mailsPerHour();
    }

    /** @return array{loadProfile: string, mailsPerHour: ?int, mailsPerHourOverride: ?int, batchSize: int, jobsPerRequest: int, nightOnly: bool} */
    public function siteSettings(): array
    {
        $p = $this->profile();
        $v = $this->settings?->get(self::SITE, 'mailsPerHour');
        return [
            'loadProfile' => $p->value,
            'mailsPerHour' => $this->mailsPerHour(),
            'mailsPerHourOverride' => is_numeric($v) && (int) $v > 0 ? (int) $v : null,
            'batchSize' => $p->batchSize(),
            'jobsPerRequest' => $p->jobsPerRequest(),
            'nightOnly' => $p->nightOnly(),
        ];
    }

    /**
     * @param array<string, mixed> $input loadProfile, mailsPerHour ('' or 0: the profile's)
     */
    public function saveSite(array $input, ?int $actorId): ValidationErrors
    {
        $errors = $this->validateSite($input);
        $profile = LoadProfile::tryFrom((string) ($input['loadProfile'] ?? ''));
        $mails = trim((string) ($input['mailsPerHour'] ?? ''));
        if (!$errors->isEmpty() || $profile === null) {
            return $errors;
        }
        $before = $this->siteSettings();
        $this->settings?->set(self::SITE, 'loadProfile', $profile->value);
        $this->settings?->set(self::SITE, 'mailsPerHour', $mails === '' ? 0 : (int) $mails);
        $this->audit?->log(null, $actorId, 'load_profile', null, 'update', $before, $this->siteSettings());
        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function validateSite(array $input): ValidationErrors
    {
        $errors = new ValidationErrors();
        $profile = LoadProfile::tryFrom((string) ($input['loadProfile'] ?? ''));
        if ($profile === null) {
            $errors->add('loadProfile', self::K . 'error.profile');
        }
        $mails = trim((string) ($input['mailsPerHour'] ?? ''));
        if ($mails !== '' && (!ctype_digit($mails) || (int) $mails > self::MAX_MAILS_PER_HOUR)) {
            $errors->add('mailsPerHour', self::K . 'error.mails');
        }
        return $errors;
    }

    public function batchSize(): int
    {
        return $this->profile()->batchSize();
    }

    /**
     * When mass work of the journal may run: null now, or the moment.
     */
    public function deferUntil(int $contextId, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $at = $this->profile()->windowOpensAt($now, $this->serverZone);
        $deadline = $this->stats->deadlineBetween($contextId, $now, $now->modify('+' . self::QUIET_HOURS . ' hours'));
        if ($deadline !== null) {
            $after = $deadline->modify('+1 minute');
            $at = $at === null || $after > $at ? $after : $at;
            // After the deadline the night window may be closed again.
            $again = $this->profile()->windowOpensAt($at, $this->serverZone);
            $at = $again ?? $at;
        }
        return $at;
    }

    /**
     * Letters a mailing of a journal may send now: at most a batch, within
     * the hourly limit, and a fair part of what is left of it when several
     * journals are sending.
     */
    public function mailAllowance(DateTimeImmutable $now): int
    {
        $batch = $this->batchSize();
        $perHour = $this->mailsPerHour();
        if ($perHour === null) {
            return $batch;
        }
        $left = max(0, $perHour - $this->stats->mailsSentSince($now->modify('-1 hour')));
        $share = (int) ceil($left / max(1, $this->stats->contextsWithPendingMail()));
        return min($batch, $left, $share);
    }
}
