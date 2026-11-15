<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Communication\ParticipantMailTexts;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ReviewMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\Bid;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\BidPlanner;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\BidRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * DEC-10: reviewers of the journal say which submissions of an edition
 * they would like to review; the manager gets a proposed distribution and
 * assigns it in OJS with one click. Works in the standard and the
 * two-phase review modes; the light mode has no reviewers.
 *
 * Reviewers see titles, sections, keywords and abstracts, never authors,
 * and never their own submissions.
 */
final class ReviewBiddingService
{
    private const K = 'plugins.generic.conferenceManager.bids.error.';
    private const STAGE_SUBMISSION = 1;
    private const STAGE_REVIEW = 3;
    private const STATUS_QUEUED = 1;

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly RegistrationRepository $registrations,
        private readonly BidRepository $bids,
        private readonly ReviewerDirectory $directory,
        private readonly ReviewCommitteeService $committee,
        private readonly ?ParticipantMailer $mailer = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    private static function today(Edition $edition, DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone($edition->timezone))->format('Y-m-d');
    }

    /**
     * Submissions of the edition that can still be reviewed: not declined
     * or published, in the submission or the review stage.
     *
     * @return array<int, array{contextId: int, stageId: int, status: int, title: string, section: string, abstract: string, keywords: list<string>, roundId: ?int, assigned: list<int>, assignedNames?: array<int, string>, authors: list<int>}>
     */
    private function biddable(ContextInfo $context, Edition $edition, string $locale): array
    {
        $ids = array_values(array_unique(array_map(static fn ($r): int => $r->submissionId, $this->registrations->registeredOfEdition((int) $edition->id))));
        sort($ids);
        return array_filter(
            $this->directory->submissions($ids, $locale),
            static fn (array $s): bool => $s['contextId'] === $context->id && $s['status'] === self::STATUS_QUEUED && in_array($s['stageId'], [self::STAGE_SUBMISSION, self::STAGE_REVIEW], true),
        );
    }

    private function usable(Edition $edition): bool
    {
        return $edition->reviewMode !== ReviewMode::Light && $edition->status !== EditionStatus::Archived;
    }

    /**
     * The page of a reviewer; null when they are not a reviewer of the
     * journal or the edition does not collect bids.
     *
     * @return array{open: bool, until: string, items: list<array{id: int, title: string, section: string, abstract: string, keywords: list<string>, bid: ?string}>}|null
     */
    public function reviewerPage(ContextInfo $context, Edition $edition, int $userId, string $locale, DateTimeImmutable $now): ?array
    {
        $settings = $this->committee->settings($context, (int) $edition->id);
        if ($settings === null || $settings->biddingUntil === null || !$this->usable($edition) || !$this->directory->isReviewer($context->id, $userId)) {
            return null;
        }
        $subs = array_filter($this->biddable($context, $edition, $locale), static fn (array $s): bool => !in_array($userId, $s['authors'], true));
        $bids = $this->bids->ofSubmissions(array_keys($subs));
        $items = [];
        foreach ($subs as $id => $s) {
            $items[] = ['id' => $id, 'title' => $s['title'], 'section' => $s['section'], 'abstract' => $s['abstract'], 'keywords' => $s['keywords'], 'bid' => ($bids[$id][$userId] ?? null)?->value];
        }
        return ['open' => $settings->biddingOpenOn(self::today($edition, $now)), 'until' => $settings->biddingUntil, 'items' => $items];
    }

    /**
     * Saves the bids a reviewer sent; a field left empty removes the bid.
     *
     * @param array<int|string, mixed> $input submission id => bid value
     * @return array{0: int, 1: ValidationErrors}|null saved count; null when the page is not theirs
     */
    public function saveBids(ContextInfo $context, Edition $edition, int $userId, array $input, string $locale, DateTimeImmutable $now): ?array
    {
        $page = $this->reviewerPage($context, $edition, $userId, $locale, $now);
        if ($page === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if (!$page['open']) {
            $errors->add('bids', self::K . 'closed');
            return [0, $errors];
        }
        $saved = 0;
        $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        foreach ($page['items'] as $item) {
            if (!array_key_exists($item['id'], $input) && !array_key_exists((string) $item['id'], $input)) {
                continue;
            }
            $value = trim((string) ($input[$item['id']] ?? ''));
            $bid = $value === '' ? null : Bid::tryFrom($value);
            if ($value !== '' && $bid === null) {
                $errors->add('bids', self::K . 'value');
                continue;
            }
            if ($bid?->value !== $item['bid']) {
                $this->bids->save($item['id'], $userId, $bid, $stamp);
                $saved++;
            }
        }
        return [$saved, $errors];
    }

    /**
     * Editions of the journal where this reviewer can bid now, for the cabinet.
     *
     * @return list<array{name: string, path: string, until: string}>
     */
    public function openFor(ContextInfo $context, int $userId, string $locale, DateTimeImmutable $now): array
    {
        if (!$this->directory->isReviewer($context->id, $userId)) {
            return [];
        }
        $out = [];
        foreach ($this->editions->list($context->id, [EditionStatus::CfpOpen, EditionStatus::CfpClosed, EditionStatus::Held], 100) as $edition) {
            $settings = $this->committee->settings($context, (int) $edition->id);
            if ($settings !== null && $this->usable($edition) && $settings->biddingOpenOn(self::today($edition, $now))) {
                $out[] = ['name' => (string) $edition->text('name')->resolve($locale, $context->primaryLocale), 'path' => $edition->path, 'until' => (string) $settings->biddingUntil];
            }
        }
        return $out;
    }

    /**
     * The manager's screen: bids per submission, reviewers with their
     * load, and the proposed distribution of submissions already in review.
     *
     * @return array<string, mixed>|null
     */
    public function overview(ContextInfo $context, int $editionId, string $locale, DateTimeImmutable $now): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        $settings = $this->committee->settings($context, $editionId);
        if ($edition === null || $settings === null) {
            return null;
        }
        $reviewers = $this->directory->reviewers($context->id, $locale);
        $names = array_column($reviewers, 'name', 'userId');
        $subs = $this->usable($edition) ? $this->biddable($context, $edition, $locale) : [];
        $bids = $this->bids->ofSubmissions(array_keys($subs));
        $loads = $this->directory->loads($context->id, array_keys($names));
        $proposal = BidPlanner::plan(
            array_filter($subs, static fn (array $s): bool => $s['roundId'] !== null),
            $bids,
            $loads,
            array_keys($names),
            $settings->reviewersPerSubmission,
        );
        $positive = [];
        $list = [];
        foreach ($subs as $id => $s) {
            $groups = array_fill_keys(array_map(static fn (Bid $b): string => $b->value, Bid::cases()), []);
            foreach ($bids[$id] ?? [] as $userId => $bid) {
                if (isset($names[$userId])) {
                    $groups[$bid->value][] = $names[$userId];
                    if ($bid->weight() > 0) {
                        $positive[$userId] = ($positive[$userId] ?? 0) + 1;
                    }
                }
            }
            $list[] = [
                'id' => $id,
                'title' => $s['title'],
                'section' => $s['section'],
                'inReview' => $s['roundId'] !== null,
                'assigned' => array_map(static fn (int $u): string => ($s['assignedNames'][$u] ?? '') !== '' ? $s['assignedNames'][$u] : ($names[$u] ?? '#' . $u), $s['assigned']),
                'bids' => $groups,
            ];
        }
        return [
            'mode' => $edition->reviewMode->value,
            'usable' => $this->usable($edition),
            'settings' => ['biddingUntil' => $settings->biddingUntil, 'reviewersPerSubmission' => $settings->reviewersPerSubmission],
            'open' => $settings->biddingOpenOn(self::today($edition, $now)),
            'bidUrl' => $this->mailer === null ? '' : $this->mailer->editionUrl($context, $edition->path) . '/bids',
            'reviewers' => array_map(static fn (array $r): array => ['userId' => $r['userId'], 'name' => $r['name'], 'email' => $r['email'], 'load' => $loads[$r['userId']] ?? 0, 'bids' => $positive[$r['userId']] ?? 0], $reviewers),
            'submissions' => $list,
            'proposal' => array_map(static fn (array $p): array => [
                'submissionId' => $p['submissionId'],
                'userId' => $p['userId'],
                'bid' => $p['bid']->value,
                'title' => $subs[$p['submissionId']]['title'],
                'name' => $names[$p['userId']] ?? '',
            ], $proposal),
        ];
    }

    /**
     * Assigns reviewers the manager confirmed. Only pairs the distribution
     * could propose pass: the submission is in review, the reviewer bid
     * for it and is neither an author nor already assigned.
     *
     * @param list<mixed> $pairs [{submissionId, userId}]
     * @return array{assigned: int, skipped: int}|null
     */
    public function assign(ContextInfo $context, int $editionId, array $pairs, int $actorId, string $locale): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        if ($edition === null) {
            return null;
        }
        $subs = $this->usable($edition) ? $this->biddable($context, $edition, $locale) : [];
        $bids = $this->bids->ofSubmissions(array_keys($subs));
        $reviewers = array_column($this->directory->reviewers($context->id, $locale), null, 'userId');
        $assigned = 0;
        $skipped = 0;
        $seen = [];
        foreach ($pairs as $pair) {
            $sid = is_array($pair) ? (int) ($pair['submissionId'] ?? 0) : 0;
            $uid = is_array($pair) ? (int) ($pair['userId'] ?? 0) : 0;
            $s = $subs[$sid] ?? null;
            $bid = $bids[$sid][$uid] ?? null;
            if ($s === null || $s['roundId'] === null || $bid === null || $bid->weight() === 0 || !isset($reviewers[$uid])
                || in_array($uid, $s['assigned'], true) || in_array($uid, $s['authors'], true) || isset($seen[$sid . ':' . $uid])) {
                $skipped++;
                continue;
            }
            $seen[$sid . ':' . $uid] = true;
            $done = $this->directory->assign($context->id, $sid, $s['roundId'], $uid, $actorId);
            if ($done === null) {
                $skipped++;
                continue;
            }
            $assigned++;
            $this->audit?->log($context->id, $actorId, 'review_bid', $sid, 'assigned', null, ['reviewerId' => $uid, 'bid' => $bid->value, 'reviewId' => $done['reviewId']]);
            try {
                $this->mailer?->sendTemplate($context, ParticipantMailTexts::BID_ASSIGNED, $reviewers[$uid]['email'], $reviewers[$uid]['name'], [
                    'recipientName' => $reviewers[$uid]['name'],
                    'conferenceName' => (string) $edition->text('name')->resolve($context->primaryLocale),
                    'submissionTitle' => $s['title'],
                    'reviewUrl' => $this->directory->reviewUrl($context->id, $sid),
                    'responseDue' => $done['responseDue'],
                    'reviewDue' => $done['reviewDue'],
                ], $context->primaryLocale);
            } catch (\Throwable $e) {
                error_log('conferenceManager: review request by bid: ' . $e->getMessage());
            }
        }
        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * Invites every reviewer of the journal to choose, while the choice is open.
     *
     * @return array{0: ?int, 1: ValidationErrors}|null sent count
     */
    public function invite(ContextInfo $context, int $editionId, int $actorId, string $locale, DateTimeImmutable $now): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        $settings = $this->committee->settings($context, $editionId);
        if ($edition === null || $settings === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if (!$this->usable($edition) || !$settings->biddingOpenOn(self::today($edition, $now)) || $this->mailer === null) {
            $errors->add('biddingUntil', self::K . 'notOpen');
            return [null, $errors];
        }
        $url = $this->mailer->editionUrl($context, $edition->path) . '/bids';
        $sent = 0;
        foreach ($this->directory->reviewers($context->id, $locale) as $r) {
            try {
                $this->mailer->sendTemplate($context, ParticipantMailTexts::BIDDING, $r['email'], $r['name'], [
                    'recipientName' => $r['name'],
                    'conferenceName' => (string) $edition->text('name')->resolve($context->primaryLocale),
                    'bidUrl' => $url,
                    'biddingUntil' => (string) $settings->biddingUntil,
                ], $context->primaryLocale);
                $sent++;
            } catch (\Throwable $e) {
                error_log('conferenceManager: bidding invitation: ' . $e->getMessage());
            }
        }
        $this->audit?->log($context->id, $actorId, 'review_bid', $editionId, 'invited', null, ['sent' => $sent]);
        return [$sent, $errors];
    }
}
