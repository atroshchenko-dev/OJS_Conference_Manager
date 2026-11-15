<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\CampaignRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PaymentFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\LocalizedText;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * Mass mailings to participants of an edition (COM-02). The manager picks
 * recipients with filters (role, category, payment, section, accepted
 * material, full text), writes the letter with variables, sends a test to
 * themselves and starts it. Letters go in portions through the OJS queue;
 * every recipient has a delivery state, so the page shows what was sent
 * and what failed. One letter per person, even with several roles.
 */
final class CampaignService
{
    public const MAX_RECIPIENTS = 5000;
    public const BATCH = 50;
    public const VARIABLES = ['recipientName', 'conferenceName', 'cabinetUrl', 'contextName'];
    private const K = 'plugins.generic.conferenceManager.campaign.';

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly ParticipantRepository $participants,
        private readonly EditionRepository $editions,
        private readonly EditorialDirectory $editorial,
        private readonly ParticipantMailer $mailer,
        private readonly CampaignQueue $queue,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Normalized filter: role, categoryId, payment, section, accepted, fullText.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function filter(array $input): array
    {
        $category = $input['categoryId'] ?? null;
        $fullText = (string) ($input['fullText'] ?? '');
        return [
            'role' => ParticipantRole::tryFrom((string) ($input['role'] ?? ''))?->value,
            'categoryId' => $category === null || $category === '' ? null : (int) $category,
            'payment' => PaymentFilter::tryFrom((string) ($input['payment'] ?? ''))?->value,
            'section' => trim((string) ($input['section'] ?? '')),
            'accepted' => !empty($input['accepted']) || in_array($fullText, ['yes', 'no'], true),
            'fullText' => in_array($fullText, ['yes', 'no'], true) ? $fullText : '',
        ];
    }

    /**
     * @param array<string, mixed> $filter from filter()
     * @return list<array{participantId: ?int, email: string, name: string}>|null
     */
    public function recipients(ContextInfo $context, int $editionId, array $filter, string $locale): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $base = new ParticipantFilter(
            ParticipantRole::tryFrom((string) $filter['role']),
            $filter['categoryId'],
            ParticipationStatus::Active,
            null,
            '',
            ParticipantFilter::MAX_PAGE,
            0,
            PaymentFilter::tryFrom((string) $filter['payment']),
        );
        $rows = [];
        for ($offset = 0; ; $offset += ParticipantFilter::MAX_PAGE) {
            $page = $this->participants->register($context->id, $editionId, new ParticipantFilter(
                $base->role,
                $base->categoryId,
                $base->status,
                null,
                '',
                ParticipantFilter::MAX_PAGE,
                $offset,
                $base->payment,
            ), $locale);
            $rows = [...$rows, ...$page['items']];
            if (count($page['items']) < ParticipantFilter::MAX_PAGE || $offset > 50000) {
                break;
            }
        }
        $needsSubmission = $filter['section'] !== '' || $filter['accepted'];
        if ($needsSubmission) {
            $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => $r['submissionId'], $rows))));
            $summaries = $this->editorial->summaries($ids, $locale);
            $text = $filter['fullText'] === '' ? [] : $this->editorial->hasFullText($ids);
            $rows = array_values(array_filter($rows, static function (array $r) use ($filter, $summaries, $text): bool {
                $s = $r['submissionId'] === null ? null : ($summaries[$r['submissionId']] ?? null);
                if ($s === null) {
                    return false;
                }
                if ($filter['section'] !== '' && $s['section'] !== $filter['section']) {
                    return false;
                }
                if ($filter['accepted'] && ($s['stageId'] < EditorialDirectory::STAGE_EDITING || $s['status'] === EditorialDirectory::STATUS_DECLINED)) {
                    return false;
                }
                if ($filter['fullText'] !== '' && ($text[$r['submissionId']] ?? false) !== ($filter['fullText'] === 'yes')) {
                    return false;
                }
                return true;
            }));
        }
        $out = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim($r['email']));
            if ($key !== '' && !isset($out[$key])) {
                $out[$key] = ['participantId' => (int) $r['participantId'], 'email' => (string) $r['email'], 'name' => (string) $r['name']];
            }
        }
        return array_values($out);
    }

    /**
     * @param array<string, mixed> $input filter fields
     * @return array{count: int, sample: list<array{email: string, name: string}>, max: int}|null
     */
    public function preview(ContextInfo $context, int $editionId, array $input, string $locale): ?array
    {
        $list = $this->recipients($context, $editionId, self::filter($input), $locale);
        if ($list === null) {
            return null;
        }
        return [
            'count' => count($list),
            'sample' => array_map(static fn (array $r): array => ['email' => $r['email'], 'name' => $r['name']], array_slice($list, 0, 20)),
            'max' => self::MAX_RECIPIENTS,
        ];
    }

    /** @param array<string, mixed> $input subject, body */
    public function validate(array $input): ValidationErrors
    {
        $errors = new ValidationErrors();
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 255) {
            $errors->add('subject', self::K . 'error.subject');
        }
        if ($body === '' || mb_strlen($body) > 20000) {
            $errors->add('body', self::K . 'error.body');
        }
        preg_match_all('/\{\$([A-Za-z]+)\}/', $subject . ' ' . $body, $m);
        $unknown = array_diff(array_unique($m[1]), self::VARIABLES);
        if ($unknown !== []) {
            $errors->add('body', self::K . 'error.variable');
        }
        return $errors;
    }

    /**
     * The letter to the manager themselves, filled as for the first recipient.
     *
     * @param array<string, mixed> $input subject, body and the filter
     */
    public function sendTest(ContextInfo $context, int $editionId, array $input, string $email, string $name, string $locale): ?ValidationErrors
    {
        $edition = $this->editions->find($context->id, $editionId);
        if ($edition === null) {
            return null;
        }
        $errors = $this->validate($input);
        if ($email === '') {
            $errors->add('email', self::K . 'error.noEmail');
        }
        if (!$errors->isEmpty()) {
            return $errors;
        }
        try {
            $this->mailer->sendText($context, '[TEST] ' . trim((string) $input['subject']), (string) $input['body'], $email, $name !== '' ? $name : null, [
                'recipientName' => $name !== '' ? $name : $email,
                'conferenceName' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale),
                'cabinetUrl' => $this->mailer->cabinetUrl($context),
                'contextName' => (string) (new LocalizedText($context->name))->resolve($locale, $context->primaryLocale),
            ]);
        } catch (\Throwable $e) {
            $errors->add('email', self::K . 'error.send');
            error_log('conferenceManager: test letter failed: ' . $e->getMessage());
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $input subject, body and the filter
     * @return array{0: ?int, 1: ValidationErrors}|null
     */
    public function start(ContextInfo $context, int $editionId, array $input, ?int $actorId, string $locale, DateTimeImmutable $now): ?array
    {
        $filter = self::filter($input);
        $list = $this->recipients($context, $editionId, $filter, $locale);
        if ($list === null) {
            return null;
        }
        $errors = $this->validate($input);
        if ($list === []) {
            $errors->add('filter', self::K . 'error.none');
        } elseif (count($list) > self::MAX_RECIPIENTS) {
            $errors->add('filter', self::K . 'error.tooMany');
        }
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $id = $this->campaigns->create($context->id, $editionId, $actorId, $locale, trim((string) $input['subject']), trim((string) $input['body']), $filter, $list, $now);
        $this->audit?->log($context->id, $actorId, 'campaign', $id, 'started', null, ['editionId' => $editionId, 'recipients' => count($list), 'filter' => $filter]);
        $url = $this->mailer->cabinetUrl($context);
        for ($n = 0; $n < count($list); $n += self::BATCH) {
            $this->queue->push($context->id, $id, $url);
        }
        return [$id, $errors];
    }

    /**
     * One portion of letters. Returns how many were taken; the job runs
     * again while something is left (a worker that died leaves its portion
     * in "sending": it is not sent twice).
     */
    public function runBatch(ContextInfo $context, int $campaignId, string $cabinetUrl, DateTimeImmutable $now): int
    {
        $c = $this->campaigns->find($context->id, $campaignId);
        if ($c === null) {
            return 0;
        }
        $edition = $this->editions->find($context->id, (int) $c['editionId']);
        $locale = (string) $c['locale'];
        $conference = $edition === null ? '' : (string) $edition->text('name')->resolve($locale, $context->primaryLocale);
        $contextName = (string) (new LocalizedText($context->name))->resolve($locale, $context->primaryLocale);
        $batch = $this->campaigns->claim($campaignId, self::BATCH);
        foreach ($batch as $r) {
            try {
                $this->mailer->sendText($context, (string) $c['subject'], (string) $c['body'], $r['email'], $r['name'] !== '' ? $r['name'] : null, [
                    'recipientName' => $r['name'] !== '' ? $r['name'] : $r['email'],
                    'conferenceName' => $conference,
                    'cabinetUrl' => $cabinetUrl,
                    'contextName' => $contextName,
                ]);
                $this->campaigns->finish($r['id'], true, null, $now);
            } catch (\Throwable $e) {
                $this->campaigns->finish($r['id'], false, $e->getMessage(), $now);
            }
        }
        return count($batch);
    }

    /** @return array<string, mixed>|null */
    public function status(ContextInfo $context, int $campaignId): ?array
    {
        $c = $this->campaigns->find($context->id, $campaignId);
        return $c === null ? null : $this->present($c);
    }

    /** @return list<array<string, mixed>>|null */
    public function ofEdition(ContextInfo $context, int $editionId): ?array
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        return array_map($this->present(...), $this->campaigns->ofEdition($context->id, $editionId, 20));
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function present(array $c): array
    {
        $counts = $this->campaigns->counts((int) $c['id']);
        return [
            'id' => $c['id'],
            'subject' => $c['subject'],
            'body' => $c['body'],
            'filter' => $c['filter'],
            'total' => $c['total'],
            'createdAt' => $c['createdAt'],
            'counts' => $counts,
            'finished' => $counts['pending'] === 0 && $counts['sending'] === 0,
            'failed' => $counts['failed'] > 0 ? $this->campaigns->failed((int) $c['id'], 100) : [],
        ];
    }
}
