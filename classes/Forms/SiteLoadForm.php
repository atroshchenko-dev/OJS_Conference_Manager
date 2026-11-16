<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Forms;

use APP\plugins\generic\conferenceManager\classes\Application\LoadPolicy;
use APP\plugins\generic\conferenceManager\classes\Domain\Site\LoadProfile;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

/**
 * DST-04 and plan, section 15.2: the overview of all journals for the site
 * administrator and the load profile of the installation.
 */
class SiteLoadForm extends Form
{
    private const K = 'plugins.generic.conferenceManager.site.';

    /**
     * @param array<string, mixed> $overview
     */
    public function __construct(
        private readonly \PKP\plugins\Plugin $plugin,
        private readonly LoadPolicy $policy,
        private readonly array $overview,
        private readonly ?int $actorId,
    ) {
        parent::__construct($plugin->getTemplateResource('siteOverview.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData(): void
    {
        $s = $this->policy->siteSettings();
        $this->_data = ['loadProfile' => $s['loadProfile'], 'mailsPerHour' => $s['mailsPerHourOverride'] ?? ''];
    }

    public function readInputData(): void
    {
        $this->readUserVars(['loadProfile', 'mailsPerHour']);
    }

    public function validate($callHooks = true): bool
    {
        if (!parent::validate($callHooks)) {
            return false;
        }
        $errors = $this->policy->validateSite($this->input());
        foreach ($errors->toArray() as $field => $keys) {
            foreach ($keys as $key) {
                $this->addError($field, __($key));
            }
        }
        return $errors->isEmpty();
    }

    public function execute(...$functionArgs)
    {
        $this->policy->saveSite($this->input(), $this->actorId);
        return parent::execute(...$functionArgs);
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['loadProfile' => (string) $this->getData('loadProfile'), 'mailsPerHour' => (string) $this->getData('mailsPerHour')];
    }

    public function fetch($request, $template = null, $display = false)
    {
        $profiles = [];
        foreach (LoadProfile::cases() as $p) {
            $profiles[$p->value] = __(self::K . 'profile.' . $p->value);
        }
        $current = $this->policy->profile();
        $o = $this->overview;
        $advice = [];
        if ($current->jobsPerRequest() === 0 && $o['jobRunner']) {
            $advice[] = __(self::K . 'advice.cron');
        } elseif ($current->jobsPerRequest() > 0 && !$o['jobRunner']) {
            $advice[] = __(self::K . 'advice.runnerOff');
        } elseif ($current->jobsPerRequest() > 0 && $o['jobRunnerMaxJobs'] > $current->jobsPerRequest()) {
            $advice[] = __(self::K . 'advice.maxJobs', ['n' => $current->jobsPerRequest(), 'current' => $o['jobRunnerMaxJobs']]);
        }
        if ($o['failedJobs'] > 0) {
            $advice[] = __(self::K . 'advice.failed', ['n' => $o['failedJobs']]);
        }
        // RPT-03: the scheduler does not run.
        $health = \APP\plugins\generic\conferenceManager\classes\Domain\Site\SchedulerHealth::check(
            \APP\plugins\generic\conferenceManager\classes\Domain\Site\SchedulerHealth::lastRun($o['taskRuns']),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        if ($health['status'] === 'never') {
            array_unshift($advice, __('plugins.generic.conferenceManager.scheduler.never'));
        } elseif ($health['status'] === 'stale') {
            array_unshift($advice, __('plugins.generic.conferenceManager.scheduler.stale', ['minutes' => $health['minutes']]));
        }
        $runs = [];
        foreach (['EditionWindowsTask', 'ReminderTask', 'RetentionTask', 'GatewayStatusTask'] as $task) {
            $run = $o['taskRuns'][$task] ?? null;
            $runs[] = [
                'name' => __(self::K . 'task.' . $task),
                'at' => is_array($run) ? (string) ($run['at'] ?? '') : '',
                'ok' => is_array($run) ? (bool) ($run['ok'] ?? false) : null,
            ];
        }
        $presets = \APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory::presets();
        TemplateManager::getManager($request)->assign([
            'pluginName' => $this->plugin->getName(),
            'profileOptions' => $profiles,
            'siteSettings' => $this->policy->siteSettings(),
            'overview' => $o,
            'taskRuns' => $runs,
            'advice' => $advice,
            'presetCount' => count($presets->all()),
            'presetProblems' => array_map(static fn (array $p): array => ['file' => $p['file'], 'text' => implode('; ', $p['errors'])], $presets->problems()),
            'presetFolder' => rtrim((string) \PKP\config\Config::getVar('files', 'files_dir'), '/') . '/conferenceManager/presets',
        ]);
        return parent::fetch($request, $template, $display);
    }
}
