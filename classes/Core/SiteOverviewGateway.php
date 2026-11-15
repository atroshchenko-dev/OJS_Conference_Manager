<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;

/**
 * DST-04: what the site administrator sees about the plugin in all journals
 * of the installation: where it is on, its schema version, the background
 * queue and the last runs of the scheduled tasks.
 */
final class SiteOverviewGateway
{
    private const JOB_MARK = 'conferenceManager';
    /** Queued jobs read to count them by journal. */
    private const JOBS_READ = 5000;

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $jobs = $this->jobsByContext();
        $settings = ServiceFactory::settings();
        $contexts = [];
        foreach (Application::getContextDAO()->getAll()->toIterator() as $journal) {
            $id = (int) $journal->getId();
            $enabled = (bool) $settings->get($id, 'enabled');
            $editions = DB::table('conf_editions')->where('context_id', $id)
                ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('cfp_open', 'cfp_closed', 'published') THEN 1 ELSE 0 END) as active")->first();
            $contexts[] = [
                'id' => $id,
                'path' => (string) $journal->getPath(),
                'name' => (string) $journal->getLocalizedName(),
                'enabled' => $enabled,
                'editions' => (int) ($editions->total ?? 0),
                'activeEditions' => (int) ($editions->active ?? 0),
                'participants' => (int) DB::table('conf_participants')->where('context_id', $id)->count(),
                'pendingLetters' => (int) DB::table('conf_campaign_recipients as r')->join('conf_campaigns as c', 'c.id', '=', 'r.campaign_id')
                    ->where('c.context_id', $id)->where('r.status', 'pending')->count(),
                'queuedJobs' => $jobs['byContext'][$id] ?? 0,
            ];
        }
        $steps = DB::table('conf_schema_steps')->orderBy('step')->pluck('step')->all();
        $runs = $settings->get(0, 'taskRuns');
        $runner = Config::getVar('queues', 'job_runner', true);
        return [
            'version' => (string) (DB::table('versions')->where('product_type', 'plugins.generic')->where('product', 'conferenceManager')
                ->where('current', 1)->selectRaw("CONCAT(major, '.', minor, '.', revision, '.', build) as v")->value('v') ?? ''),
            'schemaSteps' => count($steps),
            'schemaLast' => (string) (end($steps) ?: ''),
            'contexts' => $contexts,
            'queuedJobs' => $jobs['total'],
            'allQueuedJobs' => (int) DB::table('jobs')->count(),
            'failedJobs' => (int) DB::table('failed_jobs')->where('payload', 'like', '%' . self::JOB_MARK . '%')->count(),
            'taskRuns' => is_array($runs) ? $runs : [],
            'jobRunner' => (bool) $runner,
            'jobRunnerMaxJobs' => (int) Config::getVar('queues', 'job_runner_max_jobs', 30),
            'serverTimezone' => date_default_timezone_get(),
        ];
    }

    /** @return array{total: int, byContext: array<int, int>} */
    private function jobsByContext(): array
    {
        $out = ['total' => 0, 'byContext' => []];
        foreach (DB::table('jobs')->where('payload', 'like', '%' . self::JOB_MARK . '%')->orderBy('id')->limit(self::JOBS_READ)->pluck('payload') as $payload) {
            ++$out['total'];
            // The command is serialized PHP inside JSON: …contextId\";i:12;…
            if (preg_match('/contextId\\\\?";i:(\d+);/', (string) $payload, $m) === 1) {
                $out['byContext'][(int) $m[1]] = ($out['byContext'][(int) $m[1]] ?? 0) + 1;
            }
        }
        return $out;
    }
}
