<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core\Decisions;

use APP\decision\Decision;
use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Core\Mail\ConferenceDecisionMailable;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AcceptanceDecision;
use APP\submission\Submission;
use Illuminate\Validation\Validator;
use PKP\context\Context;
use PKP\decision\DecisionType;
use PKP\decision\Steps;
use PKP\decision\steps\Email;
use PKP\decision\steps\PromoteFiles;
use PKP\decision\types\traits\InSubmissionStage;
use PKP\decision\types\traits\NotifyAuthors;
use PKP\security\Role;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submissionFile\SubmissionFile;
use PKP\user\User;

/**
 * Decisions 901–904 (DEC-01): accept the abstract as an oral, poster,
 * online or absentee presentation, skip review and move it to copyediting.
 * Works like the core "Accept and skip review" with a letter of its own;
 * what follows (type, issue, invoice) is done on DecisionAdded (DEC-02).
 */
class ConferenceAcceptance extends DecisionType
{
    use InSubmissionStage;
    use NotifyAuthors;

    private const K = 'plugins.generic.conferenceManager.decision.';

    public function __construct(public readonly AcceptanceDecision $acceptance)
    {
    }

    public function getDecision(): int
    {
        return $this->acceptance->value;
    }

    public function getNewStageId(Submission $submission, ?int $reviewRoundId): int
    {
        return WORKFLOW_STAGE_ID_EDITING;
    }

    public function getNewStatus(): ?int
    {
        return null;
    }

    public function getNewReviewRoundStatus(): ?int
    {
        return null;
    }

    public function getLabel(?string $locale = null): string
    {
        return __($this->acceptance->labelKey(), [], $locale);
    }

    public function getDescription(?string $locale = null): string
    {
        return __(self::K . 'accept.description', ['type' => __($this->acceptance->presentationType()->labelKey(), [], $locale)], $locale);
    }

    public function getLog(): string
    {
        return self::K . 'accept.log.' . $this->acceptance->presentationType()->value;
    }

    public function getCompletedLabel(): string
    {
        return __(self::K . 'accept.completed');
    }

    public function getCompletedMessage(Submission $submission): string
    {
        return __(self::K . 'accept.completed.description', [
            'title' => $submission->getCurrentPublication()?->getLocalizedFullTitle(null, 'html') ?? '',
            'type' => __($this->acceptance->presentationType()->labelKey()),
        ]);
    }

    public function mailable(Context $context, Submission $submission, Decision $decision): ConferenceDecisionMailable
    {
        return ConferenceDecisionMailable::for($this->acceptance, $context, $submission, $decision);
    }

    public function validate(array $props, Submission $submission, Context $context, Validator $validator, ?int $reviewRoundId = null)
    {
        parent::validate($props, $submission, $context, $validator, $reviewRoundId);
        if (!$submission->getCurrentPublication()?->getData('conferenceEditionId')) {
            $validator->after(static function (Validator $validator): void {
                $validator->errors()->add('decision', __(self::K . 'error.noEdition'));
            });
        }
        foreach ((array) ($props['actions'] ?? []) as $index => $action) {
            if (($action['id'] ?? null) === $this->ACTION_NOTIFY_AUTHORS) {
                $this->validateNotifyAuthorsAction($action, 'actions.' . $index, $validator, $submission);
            }
        }
    }

    public function runAdditionalActions(Decision $decision, Submission $submission, User $editor, Context $context, array $actions)
    {
        parent::runAdditionalActions($decision, $submission, $editor, $context, $actions);
        foreach ($actions as $action) {
            if (($action['id'] ?? null) === $this->ACTION_NOTIFY_AUTHORS) {
                $this->sendAuthorEmail(
                    $this->mailable($context, $submission, $decision),
                    $this->getEmailDataFromAction($action),
                    $editor,
                    $submission,
                    $context,
                );
            }
        }
    }

    public function getSteps(Submission $submission, Context $context, User $editor, ?ReviewRound $reviewRound): Steps
    {
        $steps = new Steps($this, $submission, $context);
        $authors = $steps->getStageParticipants(Role::ROLE_ID_AUTHOR);
        if (count($authors)) {
            $mailable = $this->mailable($context, $submission, $this->getFakeDecision($submission, $editor));
            $mailable->sender($editor);
            $mailable->recipients($authors);
            $steps->addStep(new Email(
                $this->ACTION_NOTIFY_AUTHORS,
                __('editor.submission.decision.notifyAuthors'),
                __(self::K . 'accept.notifyAuthorsDescription'),
                $authors,
                $mailable,
                $context->getSupportedFormLocales(),
                $this->getFileAttachers($submission, $context),
            ));
        }
        $steps->addStep((new PromoteFiles(
            'promoteFilesToReview',
            __('editor.submission.selectFiles'),
            __('editor.submission.decision.promoteFiles.copyediting'),
            SubmissionFile::SUBMISSION_FILE_FINAL,
            $submission,
            $this->getFileGenres($context->getId()),
        ))->addFileList(
            __('submission.submit.submissionFiles'),
            Repo::submissionFile()
                ->getCollector()
                ->filterBySubmissionIds([$submission->getId()])
                ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION]),
        ));
        return $steps;
    }
}
