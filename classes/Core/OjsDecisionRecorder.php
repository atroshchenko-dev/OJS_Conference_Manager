<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Application\DecisionRecorder;
use APP\plugins\generic\conferenceManager\classes\Core\Decisions\ConferenceAcceptance;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\BulkDecision;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\core\Core;
use PKP\decision\DecisionType;
use PKP\mail\mailables\DecisionInitialDeclineNotifyAuthor;
use PKP\submissionFile\SubmissionFile;
use PKP\user\User;

/**
 * Records one decision the way the decision page does, with the journal's
 * template of the letter to the authors (DEC-03, DEC-04). An acceptance
 * also sends the submission files to copyediting, as the page offers.
 */
final class OjsDecisionRecorder implements DecisionRecorder
{
    public function record(int $contextId, int $submissionId, int $decision, int $editorId): ?string
    {
        $context = app()->get('context')->get($contextId);
        $submission = Repo::submission()->get($submissionId);
        $editor = Repo::user()->get($editorId);
        if (!$context instanceof Context || !$submission instanceof Submission || (int) $submission->getData('contextId') !== $contextId) {
            return __('api.404.resourceNotFound');
        }
        if (!$editor instanceof User) {
            return __('editor.submission.workflowDecision.invalidEditor');
        }
        $type = Repo::decision()->getDecisionType($decision);
        if (!$type instanceof DecisionType || !BulkDecision::isAllowed($decision)) {
            return __('editor.submission.workflowDecision.typeInvalid');
        }
        $params = [
            'submissionId' => $submissionId,
            'decision' => $decision,
            'stageId' => $type->getStageId(),
            'editorId' => $editorId,
            'dateDecided' => Core::getCurrentDate(),
            'actions' => $this->actions($type, $context, $submission, $editor),
        ];
        $errors = Repo::decision()->validate($params, $type, $submission, $context);
        if ($errors !== []) {
            $first = reset($errors);
            return is_array($first) ? (string) reset($first) : (string) $first;
        }
        $before = DB::table('edit_decisions')->where('submission_id', $submissionId)->count();
        try {
            Repo::decision()->add(Repo::decision()->newDataObject($params));
        } catch (\Throwable $e) {
            // The decision is stored, the stage moved and the letter sent
            // before OJS updates its notifications; a failure there must not
            // mark the item as failed (and invite a second decision).
            if (DB::table('edit_decisions')->where('submission_id', $submissionId)->count() <= $before) {
                throw $e;
            }
            error_log('conferenceManager: decision ' . $decision . ' on ' . $submissionId . ' recorded, then: ' . $e->getMessage());
        }
        if ($type instanceof ConferenceAcceptance) {
            $this->promoteFiles($submissionId);
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    private function actions(DecisionType $type, Context $context, Submission $submission, User $editor): array
    {
        $authors = EditorialGateway::authorUserIds((int) $submission->getId());
        if ($authors === []) {
            return [];
        }
        $fake = Repo::decision()->newDataObject([
            'decision' => $type->getDecision(),
            'submissionId' => $submission->getId(),
            'stageId' => $type->getStageId(),
            'editorId' => $editor->getId(),
            'dateDecided' => Core::getCurrentDate(),
        ]);
        $mailable = $type instanceof ConferenceAcceptance
            ? $type->mailable($context, $submission, $fake)
            : new DecisionInitialDeclineNotifyAuthor($context, $submission, $fake);
        $template = Repo::emailTemplate()->getByKey((int) $context->getId(), $mailable::getEmailTemplateKey());
        if ($template === null) {
            return [];
        }
        $locale = (string) ($submission->getData('locale') ?: $context->getPrimaryLocale());
        $subject = (string) ($template->getData('subject', $locale) ?: $template->getData('subject', $context->getPrimaryLocale()));
        $body = (string) ($template->getData('body', $locale) ?: $template->getData('body', $context->getPrimaryLocale()));
        return [[
            'id' => DecisionType::ACTION_NOTIFY_AUTHORS,
            'to' => $authors,
            'subject' => $subject,
            'body' => $body,
            'locale' => $locale,
        ]];
    }

    private function promoteFiles(int $submissionId): void
    {
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])
            ->getMany();
        foreach ($files as $file) {
            Repo::submissionFile()->copy($file, SubmissionFile::SUBMISSION_FILE_FINAL);
        }
    }
}
