<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\plugins\generic\conferenceManager\classes\Application\ReviewerDirectory;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\log\event\PKPSubmissionEventLogEntry;
use PKP\notification\Notification;
use PKP\security\Role;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

final class ReviewerDirectoryGateway implements ReviewerDirectory
{
    private const STAGE_REVIEW = 3;

    public function reviewers(int $contextId, string $locale): array
    {
        $out = [];
        foreach ($this->reviewerIds($contextId, null) as $id) {
            $user = Repo::user()->get($id);
            if ($user !== null && !$user->getDisabled()) {
                $out[] = ['userId' => $id, 'name' => trim($user->getFullName(true, false, $locale)), 'email' => (string) $user->getEmail()];
            }
        }
        usort($out, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['userId']] <=> [mb_strtolower($b['name']), $b['userId']]);
        return $out;
    }

    public function isReviewer(int $contextId, int $userId): bool
    {
        return $this->reviewerIds($contextId, $userId) !== [];
    }

    /** @return list<int> */
    private function reviewerIds(int $contextId, ?int $userId): array
    {
        $now = Core::getCurrentDate();
        $q = DB::table('user_user_groups as uug')->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('ug.context_id', $contextId)->where('ug.role_id', Role::ROLE_ID_REVIEWER)
            ->where(static fn ($w) => $w->whereNull('uug.date_end')->orWhere('uug.date_end', '>', $now));
        if ($userId !== null) {
            $q->where('uug.user_id', $userId);
        }
        return $q->distinct()->pluck('uug.user_id')->map(static fn ($v): int => (int) $v)->all();
    }

    public function submissions(array $submissionIds, string $locale): array
    {
        $out = [];
        foreach ($submissionIds as $id) {
            $submission = Repo::submission()->get($id);
            $publication = $submission?->getCurrentPublication();
            if ($submission === null || $publication === null) {
                continue;
            }
            $sub = (string) $submission->getData('locale');
            $pick = static function (mixed $v) use ($locale, $sub): mixed {
                if (!is_array($v)) {
                    return $v;
                }
                foreach ([$locale, $sub] as $l) {
                    if (!empty($v[$l])) {
                        return $v[$l];
                    }
                }
                return array_values(array_filter($v))[0] ?? null;
            };
            $keywords = [];
            foreach ((array) ($pick($publication->getData('keywords')) ?? []) as $k) {
                $name = is_array($k) ? (string) ($k['name'] ?? '') : (string) $k;
                if (trim($name) !== '') {
                    $keywords[] = trim($name);
                }
            }
            $section = $publication->getData('sectionId') ? Repo::section()->get((int) $publication->getData('sectionId')) : null;
            $stageId = (int) $submission->getData('stageId');
            $roundId = null;
            $assigned = [];
            if ($stageId === self::STAGE_REVIEW) {
                $round = DB::table('review_rounds')->where('submission_id', $id)->where('stage_id', self::STAGE_REVIEW)->orderByDesc('round')->value('review_round_id');
                $roundId = $round === null ? null : (int) $round;
                if ($roundId !== null) {
                    $assigned = DB::table('review_assignments')->where('review_round_id', $roundId)->where('declined', 0)->where('cancelled', 0)
                        ->pluck('reviewer_id')->map(static fn ($v): int => (int) $v)->unique()->values()->all();
                }
            }
            $out[$id] = [
                'contextId' => (int) $submission->getData('contextId'),
                'stageId' => $stageId,
                'status' => (int) $submission->getData('status'),
                'title' => trim(strip_tags((string) $pick($publication->getData('title')))),
                'section' => (string) ($section?->getLocalizedTitle() ?? ''),
                'abstract' => trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $pick($publication->getData('abstract')))), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'keywords' => $keywords,
                'roundId' => $roundId,
                'assigned' => $assigned,
                'assignedNames' => array_map(static fn (int $u): string => trim((string) Repo::user()->get($u)?->getFullName(true, false, $locale)), array_combine($assigned, $assigned)),
                'authors' => $this->authorUserIds($id, $publication),
            ];
        }
        return $out;
    }

    /** @return list<int> the submitter and the users whose email an author of the submission has */
    private function authorUserIds(int $submissionId, \APP\publication\Publication $publication): array
    {
        $ids = DB::table('stage_assignments as sa')->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->where('sa.submission_id', $submissionId)->where('ug.role_id', Role::ROLE_ID_AUTHOR)
            ->pluck('sa.user_id')->map(static fn ($v): int => (int) $v)->all();
        $emails = [];
        foreach ($publication->getData('authors') ?? [] as $author) {
            $email = mb_strtolower(trim((string) $author->getEmail()));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
        if ($emails !== []) {
            foreach (DB::table('users')->whereIn(DB::raw('LOWER(email)'), $emails)->pluck('user_id') as $v) {
                $ids[] = (int) $v;
            }
        }
        return array_values(array_unique($ids));
    }

    public function loads(int $contextId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        return DB::table('review_assignments as ra')->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('s.context_id', $contextId)->whereIn('ra.reviewer_id', $userIds)
            ->whereNull('ra.date_completed')->where('ra.declined', 0)->where('ra.cancelled', 0)
            ->groupBy('ra.reviewer_id')->selectRaw('ra.reviewer_id, COUNT(*) as n')->pluck('n', 'reviewer_id')
            ->mapWithKeys(static fn ($n, $id): array => [(int) $id => (int) $n])->all();
    }

    public function assign(int $contextId, int $submissionId, int $roundId, int $reviewerId, int $actorId): ?array
    {
        $round = DB::table('review_rounds')->where('review_round_id', $roundId)->where('submission_id', $submissionId)->first();
        $submission = Repo::submission()->get($submissionId);
        $reviewer = Repo::user()->get($reviewerId);
        $context = Application::getContextDAO()->getById($contextId);
        if ($round === null || $submission === null || $reviewer === null || $context === null || (int) $submission->getData('contextId') !== $contextId) {
            return null;
        }
        if (DB::table('review_assignments')->where('review_round_id', $roundId)->where('reviewer_id', $reviewerId)->exists()) {
            return null;
        }
        $now = Core::getCurrentDate();
        $weeks = static fn (mixed $v, int $default): int => is_numeric($v) && (int) $v > 0 ? (int) $v : $default;
        $responseDue = date('Y-m-d H:i:s', strtotime('+' . $weeks($context->getData('numWeeksPerResponse'), 1) . ' week'));
        $reviewDue = date('Y-m-d H:i:s', strtotime('+' . $weeks($context->getData('numWeeksPerReview'), 4) . ' week'));
        $method = (int) ($context->getData('defaultReviewMode') ?: ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS);
        $assignment = Repo::reviewAssignment()->newDataObject([
            'submissionId' => $submissionId,
            'reviewerId' => $reviewerId,
            'dateAssigned' => $now,
            'stageId' => (int) $round->stage_id,
            'round' => (int) $round->round,
            'reviewRoundId' => $roundId,
            'reviewMethod' => $method,
        ]);
        $reviewId = Repo::reviewAssignment()->add($assignment);
        $assignment = Repo::reviewAssignment()->get($reviewId);
        if ($assignment === null) {
            return null;
        }
        Repo::reviewAssignment()->edit($assignment, [
            'dateDue' => $reviewDue,
            'dateResponseDue' => $responseDue,
            'dateNotified' => $now,
            'considered' => ReviewAssignment::REVIEW_ASSIGNMENT_NEW,
        ]);
        // Every review file of the round, as the editor's form ticks them all by default.
        $files = Repo::submissionFile()->getCollector()->filterBySubmissionIds([$submissionId])->filterByReviewRoundIds([$roundId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_REVIEW_FILE])->getMany();
        /** @var \PKP\submission\ReviewFilesDAO $reviewFiles */
        $reviewFiles = DAORegistry::getDAO('ReviewFilesDAO');
        foreach ($files as $file) {
            $reviewFiles->grant($reviewId, $file->getId());
        }
        (new NotificationManager())->createNotification(
            $reviewerId,
            Notification::NOTIFICATION_TYPE_REVIEW_ASSIGNMENT,
            $contextId,
            Application::ASSOC_TYPE_REVIEW_ASSIGNMENT,
            $reviewId,
            Notification::NOTIFICATION_LEVEL_TASK,
        );
        Repo::eventLog()->add(Repo::eventLog()->newDataObject([
            'assocType' => Application::ASSOC_TYPE_SUBMISSION,
            'assocId' => $submissionId,
            'eventType' => PKPSubmissionEventLogEntry::SUBMISSION_LOG_REVIEW_ASSIGN,
            'userId' => $actorId,
            'message' => 'log.review.reviewerAssigned',
            'isTranslated' => false,
            'dateLogged' => $now,
            'reviewAssignment' => $reviewId,
            'reviewerName' => $reviewer->getFullName(),
            'submissionId' => $submissionId,
            'stageId' => (int) $round->stage_id,
            'round' => (int) $round->round,
        ]));
        return ['reviewId' => $reviewId, 'responseDue' => substr($responseDue, 0, 10), 'reviewDue' => substr($reviewDue, 0, 10)];
    }

    public function reviewUrl(int $contextId, int $submissionId): string
    {
        $request = Application::get()->getRequest();
        $path = (string) Application::getContextDAO()->getById($contextId)?->getPath();
        return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $path, 'reviewer', 'submission', [$submissionId]);
    }
}
