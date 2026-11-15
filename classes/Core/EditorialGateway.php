<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Application\EditorialDirectory;
use Illuminate\Support\Facades\DB;
use PKP\decision\Decision;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\submissionFile\SubmissionFile;

/**
 * Editorial state of OJS submissions for conference decisions. The lists
 * read the tables directly: a bulk decision page shows hundreds of
 * abstracts and loading each submission object would be slow.
 */
final class EditorialGateway implements EditorialDirectory
{
    public function summaries(array $submissionIds, string $locale): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            $rows = DB::table('submissions as s')
                ->leftJoin('publications as p', 'p.publication_id', '=', 's.current_publication_id')
                ->whereIn('s.submission_id', $chunk)
                ->get(['s.submission_id', 's.context_id', 's.stage_id', 's.status', 's.locale', 's.current_publication_id', 'p.section_id']);
            $pubIds = $rows->pluck('current_publication_id')->filter()->map(static fn ($v): int => (int) $v)->all();
            $sectionIds = $rows->pluck('section_id')->filter()->map(static fn ($v): int => (int) $v)->unique()->all();
            $titles = self::settings('publication_settings', 'publication_id', $pubIds, 'title');
            $sections = self::settings('section_settings', 'section_id', $sectionIds, 'title');
            $authors = self::authors($pubIds, $locale);
            foreach ($rows as $r) {
                $pub = (int) $r->current_publication_id;
                $sub = (string) $r->locale;
                $out[(int) $r->submission_id] = [
                    'contextId' => (int) $r->context_id,
                    'stageId' => (int) $r->stage_id,
                    'status' => (int) $r->status,
                    'title' => trim(strip_tags(self::pick($titles[$pub] ?? [], $locale, $sub))),
                    'section' => self::pick($sections[(int) $r->section_id] ?? [], $locale, $sub),
                    'authors' => $authors[$pub] ?? '',
                ];
            }
        }
        return $out;
    }

    public function hasFullText(array $submissionIds): array
    {
        $out = array_fill_keys($submissionIds, false);
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            $accepted = DB::table('edit_decisions')->whereIn('submission_id', $chunk)
                ->whereIn('decision', [Decision::ACCEPT, Decision::SKIP_EXTERNAL_REVIEW, 901, 902, 903, 904])
                ->groupBy('submission_id')->selectRaw('submission_id, MAX(date_decided) as decided')->pluck('decided', 'submission_id')->all();
            if ($accepted === []) {
                continue;
            }
            $authors = [];
            foreach (StageAssignment::withSubmissionIds(array_map('intval', array_keys($accepted)))->withRoleIds([Role::ROLE_ID_AUTHOR])->get() as $a) {
                $authors[(int) $a->submission_id][] = (int) $a->user_id;
            }
            // Files the authors uploaded after the acceptance, in any stage
            // (also as discussion attachments); files the editor copies to
            // the next stage do not count.
            $files = DB::table('submission_files')->whereIn('submission_id', array_keys($accepted))
                ->where('file_stage', '!=', SubmissionFile::SUBMISSION_FILE_NOTE)
                ->whereNotNull('uploader_user_id')
                ->get(['submission_id', 'uploader_user_id', 'created_at']);
            foreach ($files as $f) {
                $id = (int) $f->submission_id;
                if (in_array((int) $f->uploader_user_id, $authors[$id] ?? [], true) && (string) $f->created_at > (string) $accepted[$f->submission_id]) {
                    $out[$id] = true;
                }
            }
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, string>> id => locale => value
     */
    private static function settings(string $table, string $key, array $ids, string $name): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach (DB::table($table)->whereIn($key, $ids)->where('setting_name', $name)->get([$key, 'locale', 'setting_value']) as $row) {
            $out[(int) $row->{$key}][(string) $row->locale] = (string) $row->setting_value;
        }
        return $out;
    }

    /** @param array<string, string> $values */
    private static function pick(array $values, string $locale, string $fallback): string
    {
        foreach ([$locale, $fallback] as $l) {
            if (($values[$l] ?? '') !== '') {
                return $values[$l];
            }
        }
        foreach ($values as $v) {
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /**
     * @param list<int> $publicationIds
     * @return array<int, string> "Family G., Family G."
     */
    private static function authors(array $publicationIds, string $locale): array
    {
        if ($publicationIds === []) {
            return [];
        }
        $authors = DB::table('authors')->whereIn('publication_id', $publicationIds)->orderBy('publication_id')->orderBy('seq')->get(['author_id', 'publication_id']);
        $ids = $authors->pluck('author_id')->map(static fn ($v): int => (int) $v)->all();
        $given = self::settings('author_settings', 'author_id', $ids, 'givenName');
        $family = self::settings('author_settings', 'author_id', $ids, 'familyName');
        $names = [];
        foreach ($authors as $a) {
            $id = (int) $a->author_id;
            $g = self::pick($given[$id] ?? [], $locale, $locale);
            $f = self::pick($family[$id] ?? [], $locale, $locale);
            $name = trim($f . ' ' . ($g === '' ? '' : mb_substr($g, 0, 1) . '.'));
            $names[(int) $a->publication_id][] = $name === '' ? $g : $name;
        }
        return array_map(static fn (array $n): string => implode(', ', $n), $names);
    }

    public function decidableBy(int $userId, array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($submissionIds, 500) as $chunk) {
            $assignments = StageAssignment::withSubmissionIds($chunk)
                ->withUserId($userId)
                ->withStageIds([self::STAGE_SUBMISSION])
                ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
                ->withRecommendOnly(false)
                ->get();
            foreach ($assignments as $a) {
                $out[(int) $a->submission_id] = true;
            }
        }
        return array_values(array_filter($submissionIds, static fn (int $id): bool => isset($out[$id])));
    }

    public function assignIssue(int $submissionId, int $issueId): bool
    {
        $publication = Repo::submission()->get($submissionId)?->getCurrentPublication();
        if ($publication === null || $publication->getData('issueId')) {
            return false;
        }
        if (Repo::issue()->get($issueId) === null) {
            return false;
        }
        Repo::publication()->edit($publication, ['issueId' => $issueId]);
        return true;
    }

    public function setPresentationType(int $submissionId, string $presentationType): void
    {
        $publication = Repo::submission()->get($submissionId)?->getCurrentPublication();
        if ($publication !== null && $publication->getData('presentationType') !== $presentationType) {
            Repo::publication()->edit($publication, ['presentationType' => $presentationType]);
        }
    }

    /** @return list<int> users who are authors of the submission in any stage */
    public static function authorUserIds(int $submissionId): array
    {
        return array_values(array_unique(array_map('intval', StageAssignment::withSubmissionIds([$submissionId])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->get()
            ->pluck('user_id')
            ->all())));
    }

    /** The user is a manager or section editor assigned to the submission. */
    public static function isAssignedEditor(int $userId, int $submissionId): bool
    {
        return StageAssignment::withSubmissionIds([$submissionId])
            ->withUserId($userId)
            ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
            ->exists();
    }
}
