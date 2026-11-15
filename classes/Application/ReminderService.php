<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ReminderRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Fee\Money;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reminders on a schedule (COM-03): the payment deadline, the full text
 * deadline and the start of the event. Each goes once, in the days before
 * the date that the journal chose, and only to those who have not done
 * the thing yet: an invoice paid, waived or with a receipt under review,
 * a full text already sent. Off until the journal turns them on.
 */
final class ReminderService
{
    public const SETTING = 'reminders';
    public const PAYMENT = 'payment';
    public const PAPER = 'paper';
    public const EVENT = 'event';
    public const KINDS = [self::PAYMENT => 3, self::PAPER => 7, self::EVENT => 2];
    private const K = 'plugins.generic.conferenceManager.reminder.';

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly ReminderRepository $reminders,
        private readonly EditorialDirectory $editorial,
        private readonly ParticipantMailer $mailer,
        private readonly SettingsStore $settings,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /** @return array<string, array{enabled: bool, days: int}> */
    public function settings(ContextInfo $context): array
    {
        $saved = $this->settings->get($context->id, self::SETTING);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::KINDS as $kind => $days) {
            $s = is_array($saved[$kind] ?? null) ? $saved[$kind] : [];
            $out[$kind] = [
                'enabled' => (bool) ($s['enabled'] ?? false),
                'days' => is_int($s['days'] ?? null) ? $s['days'] : $days,
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $input kind => {enabled, days} */
    public function saveSettings(ContextInfo $context, array $input, ?int $actorId): ValidationErrors
    {
        $errors = new ValidationErrors();
        $before = $this->settings($context);
        $after = $before;
        foreach (self::KINDS as $kind => $default) {
            $v = $input[$kind] ?? null;
            if (!is_array($v)) {
                continue;
            }
            $days = filter_var($v['days'] ?? $before[$kind]['days'], FILTER_VALIDATE_INT);
            if ($days === false || $days < 1 || $days > 60) {
                $errors->add($kind, self::K . 'error.days');
                continue;
            }
            $after[$kind] = ['enabled' => (bool) ($v['enabled'] ?? false), 'days' => $days];
        }
        if ($errors->isEmpty()) {
            $this->settings->set($context->id, self::SETTING, $after);
            $this->audit?->log($context->id, $actorId, 'reminders', null, 'update', $before, $after);
        }
        return $errors;
    }

    /**
     * Sends what is due now in the context.
     *
     * @return array{payment: int, paper: int, event: int, failed: int}
     */
    public function run(ContextInfo $context, DateTimeImmutable $now): array
    {
        $done = [self::PAYMENT => 0, self::PAPER => 0, self::EVENT => 0, 'failed' => 0];
        $settings = $this->settings($context);
        if (!in_array(true, array_column($settings, 'enabled'), true)) {
            return $done;
        }
        $active = [EditionStatus::CfpOpen, EditionStatus::CfpClosed];
        foreach ($this->editions->list($context->id, $active, 200) as $edition) {
            foreach ([self::PAYMENT, self::PAPER, self::EVENT] as $kind) {
                if (!$settings[$kind]['enabled']) {
                    continue;
                }
                [$sent, $failed] = match ($kind) {
                    self::PAYMENT => $this->payment($context, $edition, $settings[$kind]['days'], $now),
                    self::PAPER => $this->paper($context, $edition, $settings[$kind]['days'], $now),
                    default => $this->event($context, $edition, $settings[$kind]['days'], $now),
                };
                $done[$kind] += $sent;
                $done['failed'] += $failed;
            }
        }
        return $done;
    }

    /** @return array<string, int> kind => letters sent for the edition */
    public function sentCounts(int $editionId): array
    {
        $counts = $this->reminders->sentCounts($editionId);
        return [
            self::PAYMENT => $counts[self::PAYMENT] ?? 0,
            self::PAPER => $counts[self::PAPER] ?? 0,
            self::EVENT => $counts[self::EVENT] ?? 0,
        ];
    }

    /** True when $now is within $days before $date, the date itself included. */
    public static function isDue(?DateTimeImmutable $date, int $days, DateTimeImmutable $now): bool
    {
        return $date !== null && $now <= $date && $now >= $date->modify('-' . $days . ' days');
    }

    /** @return array{0: int, 1: int} */
    private function payment(ContextInfo $context, Edition $edition, int $days, DateTimeImmutable $now): array
    {
        $sent = $failed = 0;
        $locale = $context->primaryLocale;
        foreach ($this->reminders->unpaidInvoices((int) $edition->id, $locale) as $i) {
            $due = $i['dueAt'] ?? $edition->date('paymentDeadline');
            if ($i['email'] === '' || !self::isDue($due, $days, $now)) {
                continue;
            }
            $ok = $this->send($context, $edition, self::PAYMENT, $i['invoiceId'], ParticipantMailTexts::REMIND_PAYMENT, $i['email'], $i['name'], [
                'invoiceNumber' => $i['number'],
                'amountDue' => (new Money($i['dueMinor'], $i['currency']))->decimal() . ' ' . $i['currency'],
                'dueDate' => self::localDate($due, $edition->timezone),
                'paymentReference' => $i['reference'],
            ], $now);
            if ($ok === true) {
                $sent++;
            } elseif ($ok === false) {
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    /** @return array{0: int, 1: int} */
    private function paper(ContextInfo $context, Edition $edition, int $days, DateTimeImmutable $now): array
    {
        $sent = $failed = 0;
        $deadline = $edition->date('paperDeadline');
        if ($edition->submissionMode !== SubmissionMode::AbstractsAndPapers || !self::isDue($deadline, $days, $now)) {
            return [0, 0];
        }
        $locale = $context->primaryLocale;
        $presenters = array_values(array_filter(
            $this->reminders->participations((int) $edition->id, $locale),
            static fn (array $p): bool => $p['role'] === ParticipantRole::Presenter->value && $p['submissionId'] !== null,
        ));
        $ids = array_values(array_unique(array_map(static fn (array $p): int => (int) $p['submissionId'], $presenters)));
        $summaries = $this->editorial->summaries($ids, $locale);
        $hasText = $this->editorial->hasFullText($ids);
        foreach ($presenters as $p) {
            $sid = (int) $p['submissionId'];
            $s = $summaries[$sid] ?? null;
            if ($s === null || !self::isAccepted($s) || ($hasText[$sid] ?? false)) {
                continue;
            }
            $ok = $this->send($context, $edition, self::PAPER, $sid, ParticipantMailTexts::REMIND_PAPER, $p['email'], $p['name'], [
                'submissionTitle' => $s['title'],
                'paperDeadline' => self::localDate($deadline, $edition->timezone),
            ], $now);
            if ($ok === true) {
                $sent++;
            } elseif ($ok === false) {
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    /** @return array{0: int, 1: int} */
    private function event(ContextInfo $context, Edition $edition, int $days, DateTimeImmutable $now): array
    {
        $sent = $failed = 0;
        $starts = $edition->date('startsAt');
        if (!self::isDue($starts, $days, $now)) {
            return [0, 0];
        }
        $locale = $context->primaryLocale;
        $all = $this->reminders->participations((int) $edition->id, $locale);
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $p): ?int => $p['submissionId'], $all))));
        $summaries = $this->editorial->summaries($ids, $locale);
        $people = [];
        foreach ($all as $p) {
            $listener = $p['role'] === ParticipantRole::Listener->value;
            $s = $p['submissionId'] === null ? null : ($summaries[$p['submissionId']] ?? null);
            if ($listener || ($s !== null && self::isAccepted($s))) {
                $people[$p['participantId']] ??= $p;
            }
        }
        $venue = (string) $edition->text('venue')->resolve($locale, $context->primaryLocale);
        $url = $this->mailer->editionUrl($context, $edition->path);
        foreach ($people as $p) {
            $ok = $this->send($context, $edition, self::EVENT, $p['participantId'], ParticipantMailTexts::REMIND_EVENT, $p['email'], $p['name'], [
                'startsAt' => self::localDate($starts, $edition->timezone, 'd.m.Y H:i'),
                'venue' => $venue,
                'eventUrl' => $url,
            ], $now);
            if ($ok === true) {
                $sent++;
            } elseif ($ok === false) {
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    /**
     * Claims and sends one reminder. null = already sent before.
     *
     * @param array<string, string> $data
     */
    private function send(ContextInfo $context, Edition $edition, string $kind, int $targetId, string $template, string $email, string $name, array $data, DateTimeImmutable $now): ?bool
    {
        if ($email === '' || !$this->reminders->claim($context->id, (int) $edition->id, $kind, $targetId, $email, $now)) {
            return null;
        }
        $locale = $context->primaryLocale;
        try {
            $this->mailer->sendTemplate($context, $template, $email, $name !== '' ? $name : null, $data + [
                'recipientName' => $name !== '' ? $name : $email,
                'conferenceName' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale),
            ], $locale);
            return true;
        } catch (\Throwable $e) {
            $this->reminders->release($kind, $targetId);
            error_log('conferenceManager: reminder ' . $kind . ' #' . $targetId . ' failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @param array{stageId: int, status: int} $summary */
    private static function isAccepted(array $summary): bool
    {
        return $summary['stageId'] >= EditorialDirectory::STAGE_EDITING && $summary['status'] !== EditorialDirectory::STATUS_DECLINED;
    }

    private static function localDate(?DateTimeImmutable $d, string $timezone, string $format = 'd.m.Y'): string
    {
        return $d === null ? '' : $d->setTimezone(new DateTimeZone($timezone !== '' ? $timezone : 'UTC'))->format($format);
    }
}
