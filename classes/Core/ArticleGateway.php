<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\ArticleDirectory;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;

/** Published articles of a journal with their titles in every language. */
final class ArticleGateway implements ArticleDirectory
{
    public function published(int $contextId): array
    {
        $rows = DB::table('submissions as s')
            ->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->leftJoin('publication_settings as ps', static function ($j): void {
                $j->on('ps.publication_id', '=', 'p.publication_id')->where('ps.setting_name', '=', 'title');
            })
            ->where('s.context_id', $contextId)
            ->where('p.status', Submission::STATUS_PUBLISHED)
            ->orderBy('s.submission_id')
            ->get(['s.submission_id', 'p.date_published', 'ps.setting_value']);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->submission_id;
            $out[$id] ??= ['submissionId' => $id, 'titles' => [], 'year' => $r->date_published ? (int) substr((string) $r->date_published, 0, 4) : null];
            if ($r->setting_value !== null && $r->setting_value !== '') {
                $out[$id]['titles'][] = (string) $r->setting_value;
            }
        }
        return array_values($out);
    }
}
