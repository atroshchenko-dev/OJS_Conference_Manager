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
                // RPT-02 for the site: what each journal did with the plugin.
                'submissions' => (int) DB::table('conf_submission_registrations as r')->join('conf_editions as e', 'e.id', '=', 'r.edition_id')
                    ->where('e.context_id', $id)->count(),
                'paidInvoices' => (int) DB::table('conf_invoices')->where('context_id', $id)->whereIn('status', ['paid', 'refund_requested', 'refunded'])->count(),
                'certificates' => (int) DB::table('conf_certificates')->where('context_id', $id)->whereNull('revoked_at')->count(),
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

    /**
     * DST-04: the journals as CSV for a consortium report (UTF-8 with BOM,
     * ";" as separator).
     *
     * @param array<string, mixed> $overview
     * @param array<string, string> $header column => label
     */
    public static function csv(array $overview, array $header): string
    {
        $out = "\u{FEFF}" . implode(';', array_map(static fn (string $h): string => '"' . str_replace('"', '""', $h) . '"', array_values($header))) . "\r\n";
        foreach ($overview['contexts'] as $c) {
            $cells = [];
            foreach (array_keys($header) as $key) {
                $v = $c[$key] ?? '';
                $v = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
                if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                    $v = "'" . $v;
                }
                $cells[] = '"' . str_replace('"', '""', $v) . '"';
            }
            $out .= implode(';', $cells) . "\r\n";
        }
        return $out;
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
