<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Site;

use DateTimeImmutable;

/**
 * RPT-03: whether the OJS scheduler runs. The plugin's quickest task runs
 * every 15 minutes, so two hours without a run means cron is not set up
 * or stopped: reminders, status changes and retention do not happen.
 */
final class SchedulerHealth
{
    public const OK = 'ok';
    public const STALE = 'stale';
    public const NEVER = 'never';
    public const MAX_MINUTES = 120;

    /** @return array{status: string, minutes: ?int} */
    public static function check(?DateTimeImmutable $lastRun, DateTimeImmutable $now, int $maxMinutes = self::MAX_MINUTES): array
    {
        if ($lastRun === null) {
            return ['status' => self::NEVER, 'minutes' => null];
        }
        $minutes = max(0, intdiv($now->getTimestamp() - $lastRun->getTimestamp(), 60));
        return ['status' => $minutes > $maxMinutes ? self::STALE : self::OK, 'minutes' => $minutes];
    }

    /**
     * The latest run among the plugin's tasks, from the site setting the
     * tasks keep (see Tasks\RecordsRun).
     *
     * @param array<mixed> $runs task => {at, ok}
     */
    public static function lastRun(array $runs): ?DateTimeImmutable
    {
        $latest = null;
        foreach ($runs as $run) {
            $at = is_array($run) && is_string($run['at'] ?? null) ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $run['at'], new \DateTimeZone('UTC')) : false;
            if ($at !== false && ($latest === null || $at > $latest)) {
                $latest = $at;
            }
        }
        return $latest;
    }
}
