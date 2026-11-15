<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ReviewMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\RegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\ConflictCheck;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\ReviewSettings;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\Vote;
use APP\plugins\generic\conferenceManager\classes\Domain\Review\VoteRepository;
use APP\plugins\generic\conferenceManager\classes\Domain\Shared\ValidationErrors;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use DateTimeImmutable;

/**
 * How a submission of an edition is reviewed:
 *  - DEC-07: in the two-phase mode, the review forms of the abstract round
 *    and of the paper rounds;
 *  - DEC-08: a warning when an editor of the submission has the same
 *    affiliation as an author;
 *  - DEC-09: in the light mode, the votes of the committee (the editors of
 *    the submission) with a comment, and the total for the chair.
 */
final class ReviewCommitteeService
{
    public const SETTING = 'reviewSettings';
    public const MAX_COMMENT = 2000;
    private const K = 'plugins.generic.conferenceManager.review.error.';

    /** @param ?\Closure(string, array<string, mixed>, string): string $translate */
    public function __construct(
        private readonly EditionRepository $editions,
        private readonly RegistrationRepository $registrations,
        private readonly VoteRepository $votes,
        private readonly ReviewPeople $people,
        private readonly ?SettingsStore $settings = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?\Closure $translate = null,
    ) {
    }

    /** The edition of a submission in this journal. */
    public function editionOf(ContextInfo $context, int $submissionId): ?Edition
    {
        $editionId = $this->registrations->findBySubmission($submissionId)?->editionId;
        return $editionId === null ? null : $this->editions->find($context->id, $editionId);
    }

    /**
     * The review block of the conference panel of a submission.
     *
     * @return array<string, mixed>|null null when the submission is not of an edition of the journal
     */
    public function panel(ContextInfo $context, int $submissionId, int $viewerId, string $locale): ?array
    {
        $edition = $this->editionOf($context, $submissionId);
        if ($edition === null) {
            return null;
        }
        $voting = $edition->reviewMode === ReviewMode::Light;
        $votes = [];
        $mine = null;
        if ($voting) {
            foreach ($this->votes->ofSubmission($submissionId) as $v) {
                $name = $this->people->user($v['userId'], $locale)['name'] ?? '#' . $v['userId'];
                $row = ['userId' => $v['userId'], 'name' => $name, 'vote' => $v['vote']->value, 'voteLabel' => $this->t($v['vote']->labelKey(), $locale), 'comment' => $v['comment'], 'updatedAt' => $v['updatedAt']];
                $votes[] = $row;
                if ($v['userId'] === $viewerId) {
                    $mine = $row;
                }
            }
        }
        $counts = ['for' => 0, 'against' => 0, 'abstain' => 0];
        foreach ($votes as $v) {
            $counts[$v['vote']]++;
        }
        $settings = $this->settings($context, (int) $edition->id) ?? new ReviewSettings();
        return [
            'mode' => $edition->reviewMode->value,
            'modeLabel' => $this->t($edition->reviewMode->labelKey(), $locale),
            'voting' => $voting,
            'votes' => $votes,
            'counts' => $counts,
            'myVote' => $mine,
            'voteOptions' => array_map(fn (Vote $v): array => ['value' => $v->value, 'label' => $this->t($v->labelKey(), $locale)], Vote::cases()),
            'conflicts' => $this->conflicts($submissionId, $viewerId, $locale),
            'twoPhase' => $edition->reviewMode === ReviewMode::TwoPhase,
            'paperDeadline' => $edition->date('paperDeadline')?->format(DATE_ATOM),
            'forms' => $settings->toArray(),
        ];
    }

    /**
     * DEC-08: editors of the submission (and the viewer, who may be about
     * to take the decision) with the same affiliation as an author.
     *
     * @return list<array{userId: int, editor: string, author: string, affiliation: string, isViewer: bool}>
     */
    public function conflicts(int $submissionId, ?int $viewerId, string $locale): array
    {
        $editors = $this->people->editors($submissionId, $locale);
        if ($viewerId !== null && !in_array($viewerId, array_column($editors, 'userId'), true)) {
            $viewer = $this->people->user($viewerId, $locale);
            if ($viewer !== null) {
                $editors[] = $viewer;
            }
        }
        $found = ConflictCheck::find($editors, $this->people->authors($submissionId, $locale));
        return array_map(static fn (array $c): array => $c + ['isViewer' => $c['userId'] === $viewerId], $found);
    }

    /**
     * DEC-09: the vote of a member; "none" takes the vote back.
     *
     * @param array<string, mixed> $input vote, comment
     */
    public function vote(ContextInfo $context, int $submissionId, int $userId, array $input, DateTimeImmutable $now): ?ValidationErrors
    {
        $edition = $this->editionOf($context, $submissionId);
        if ($edition === null) {
            return null;
        }
        $errors = new ValidationErrors();
        if ($edition->reviewMode !== ReviewMode::Light) {
            $errors->add('vote', self::K . 'notLight');
            return $errors;
        }
        $value = (string) ($input['vote'] ?? '');
        $comment = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['comment'] ?? '')));
        if ($value === 'none') {
            if ($this->votes->delete($submissionId, $userId)) {
                $this->audit?->log($context->id, $userId, 'review_vote', $submissionId, 'withdraw', null, null);
            }
            return $errors;
        }
        $vote = Vote::tryFrom($value);
        if ($vote === null) {
            $errors->add('vote', self::K . 'vote');
        }
        if (mb_strlen($comment) > self::MAX_COMMENT) {
            $errors->add('comment', self::K . 'comment');
        }
        if ($vote === null || !$errors->isEmpty()) {
            return $errors;
        }
        $this->votes->save($submissionId, $userId, $vote, $comment, $now->format('Y-m-d H:i:s'));
        $this->audit?->log($context->id, $userId, 'review_vote', $submissionId, 'vote', null, ['vote' => $vote->value, 'comment' => $comment]);
        return $errors;
    }

    /**
     * Totals for a list of submissions (the bulk decision page).
     *
     * @param list<int> $submissionIds
     * @return array<int, array{for: int, against: int, abstain: int}>
     */
    public function counts(array $submissionIds): array
    {
        return $submissionIds === [] ? [] : $this->votes->counts($submissionIds);
    }

    public function settings(ContextInfo $context, int $editionId): ?ReviewSettings
    {
        if ($this->editions->find($context->id, $editionId) === null) {
            return null;
        }
        $all = $this->settings?->get($context->id, self::SETTING);
        return ReviewSettings::fromArray(is_array($all) && is_array($all[$editionId] ?? null) ? $all[$editionId] : []);
    }

    /**
     * @param array<string, mixed> $input abstractFormId, paperFormId, biddingUntil, reviewersPerSubmission
     * @return array{0: ?ReviewSettings, 1: ValidationErrors}|null
     */
    public function saveSettings(ContextInfo $context, int $editionId, array $input, ?int $actorId = null): ?array
    {
        $before = $this->settings($context, $editionId);
        if ($before === null) {
            return null;
        }
        $errors = new ValidationErrors();
        $forms = array_column($this->people->reviewForms($context->id, $context->primaryLocale), 'id');
        // Fields left out keep their values: the forms and the bidding are saved on different screens.
        $new = ReviewSettings::fromArray(array_intersect_key($input, $before->toArray()) + $before->toArray());
        $until = trim((string) ($input['biddingUntil'] ?? ''));
        if ($until !== '' && !ReviewSettings::isDate($until)) {
            $errors->add('biddingUntil', self::K . 'date');
        }
        if (array_key_exists('reviewersPerSubmission', $input)
            && filter_var($input['reviewersPerSubmission'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => ReviewSettings::MAX_PER_SUBMISSION]]) === false) {
            $errors->add('reviewersPerSubmission', self::K . 'perSubmission');
        }
        foreach (['abstractFormId' => $new->abstractFormId, 'paperFormId' => $new->paperFormId] as $field => $id) {
            if ($id !== null && !in_array($id, $forms, true)) {
                $errors->add($field, self::K . 'form');
            }
        }
        if (!$errors->isEmpty()) {
            return [null, $errors];
        }
        $all = $this->settings?->get($context->id, self::SETTING);
        $all = is_array($all) ? $all : [];
        $all[$editionId] = $new->toArray();
        $this->settings?->set($context->id, self::SETTING, $all);
        $this->audit?->log($context->id, $actorId, 'review_settings', $editionId, 'update', $before->toArray(), $new->toArray());
        return [$new, $errors];
    }

    /**
     * @return array{settings: array{abstractFormId: ?int, paperFormId: ?int, biddingUntil: ?string, reviewersPerSubmission: int}, forms: list<array{id: int, title: string}>, mode: string}|null
     */
    public function overview(ContextInfo $context, int $editionId, string $locale): ?array
    {
        $edition = $this->editions->find($context->id, $editionId);
        $settings = $this->settings($context, $editionId);
        if ($edition === null || $settings === null) {
            return null;
        }
        return ['settings' => $settings->toArray(), 'forms' => $this->people->reviewForms($context->id, $locale), 'mode' => $edition->reviewMode->value];
    }

    /**
     * DEC-07: the review form for a new reviewer of a round, when the
     * edition is in the two-phase mode and has a form for that round.
     */
    public function formForAssignment(ContextInfo $context, int $submissionId, int $reviewRoundId): ?int
    {
        $edition = $this->editionOf($context, $submissionId);
        if ($edition === null || $edition->reviewMode !== ReviewMode::TwoPhase) {
            return null;
        }
        $round = $this->people->roundNumber($reviewRoundId);
        return $round === null ? null : $this->settings($context, (int) $edition->id)?->formForRound($round);
    }

    private function t(string $key, string $locale): string
    {
        return $this->translate === null ? $key : ($this->translate)($key, [], $locale);
    }
}
