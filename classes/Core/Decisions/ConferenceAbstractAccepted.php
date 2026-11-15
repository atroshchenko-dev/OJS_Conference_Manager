<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Decisions;

use APP\decision\Decision;
use APP\plugins\generic\conferenceManager\classes\Core\Mail\AbstractAcceptedNotifyAuthor;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AbstractAccepted;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ReviewMode;
use APP\submission\Submission;
use Illuminate\Validation\Validator;
use PKP\context\Context;
use PKP\decision\DecisionType;
use PKP\decision\Steps;
use PKP\decision\steps\Email;
use PKP\decision\types\RequestRevisions;
use PKP\mail\mailables\DecisionNotifyReviewer;
use PKP\security\Role;
use PKP\submission\reviewRound\ReviewRound;
use PKP\user\User;

/**
 * DEC-07, decision 905: "abstract accepted, send the full text". Works
 * like "Request revisions" (the round waits for the author's new files)
 * with a letter of its own that gives the paper deadline of the edition.
 * Then the editor opens a new round for the paper.
 */
class ConferenceAbstractAccepted extends RequestRevisions
{
    private const K = 'plugins.generic.conferenceManager.decision.abstractAccepted.';

    public function getDecision(): int
    {
        return AbstractAccepted::DECISION;
    }

    public function getLabel(?string $locale = null): string
    {
        return __(self::K . 'label', [], $locale);
    }

    public function getDescription(?string $locale = null): string
    {
        return __(self::K . 'description', [], $locale);
    }

    public function getLog(): string
    {
        return self::K . 'log';
    }

    public function getCompletedLabel(): string
    {
        return __(self::K . 'completed');
    }

    public function getCompletedMessage(Submission $submission): string
    {
        return __(self::K . 'completed.description', ['title' => $submission->getCurrentPublication()?->getLocalizedFullTitle(null, 'html') ?? '']);
    }

    public function validate(array $props, Submission $submission, Context $context, Validator $validator, ?int $reviewRoundId = null)
    {
        parent::validate($props, $submission, $context, $validator, $reviewRoundId);
        $edition = ServiceFactory::reviewCommittee()->editionOf(\APP\plugins\generic\conferenceManager\classes\Core\ContextGateway::fromContext($context), (int) $submission->getId());
        // OJS calls this inside its own after() callback, so the error is added directly.
        if ($edition === null || $edition->reviewMode !== ReviewMode::TwoPhase) {
            $validator->errors()->add('decision', __(self::K . 'error.notTwoPhase'));
        }
    }

    public function runAdditionalActions(Decision $decision, Submission $submission, User $editor, Context $context, array $actions)
    {
        // The core letter of "Request revisions" is replaced by ours.
        DecisionType::runAdditionalActions($decision, $submission, $editor, $context, $actions);
        foreach ($actions as $action) {
            if (($action['id'] ?? null) === $this->ACTION_NOTIFY_AUTHORS) {
                $this->sendAuthorEmail(new AbstractAcceptedNotifyAuthor($context, $submission, $decision), $this->getEmailDataFromAction($action), $editor, $submission, $context);
            } elseif (($action['id'] ?? null) === $this->ACTION_NOTIFY_REVIEWERS) {
                $this->sendReviewersEmail(new DecisionNotifyReviewer($context, $submission, $decision), $this->getEmailDataFromAction($action), $editor, $submission);
            }
        }
    }

    public function getSteps(Submission $submission, Context $context, User $editor, ?ReviewRound $reviewRound): Steps
    {
        $steps = new Steps($this, $submission, $context, $reviewRound);
        $fake = $this->getFakeDecision($submission, $editor, $reviewRound);
        $attachers = $this->getFileAttachers($submission, $context, $reviewRound);
        $authors = $steps->getStageParticipants(Role::ROLE_ID_AUTHOR);
        if (count($authors)) {
            $mailable = new AbstractAcceptedNotifyAuthor($context, $submission, $fake);
            $mailable->sender($editor);
            $mailable->recipients($authors);
            $steps->addStep(new Email(
                $this->ACTION_NOTIFY_AUTHORS,
                __('editor.submission.decision.notifyAuthors'),
                __(self::K . 'notifyAuthorsDescription'),
                $authors,
                $mailable,
                $context->getSupportedFormLocales(),
                $attachers,
            ));
        }
        $assignments = $reviewRound === null ? [] : $this->getReviewAssignments($submission->getId(), $reviewRound->getId(), self::REVIEW_ASSIGNMENT_COMPLETED);
        if (count($assignments)) {
            $steps->addStep((new Email(
                $this->ACTION_NOTIFY_REVIEWERS,
                __('editor.submission.decision.notifyReviewers'),
                __('editor.submission.decision.notifyReviewers.description'),
                $steps->getReviewersFromAssignments($assignments),
                (new DecisionNotifyReviewer($context, $submission, $fake))->sender($editor),
                $context->getSupportedFormLocales(),
                $attachers,
            ))->canChangeRecipients(true)->anonymizeRecipients(true));
        }
        return $steps;
    }
}
