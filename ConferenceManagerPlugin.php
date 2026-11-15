<?php

/**
 * @file ConferenceManagerPlugin.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ConferenceManagerPlugin
 *
 * @brief Conferences, schools and seminars on top of OJS 3.5 for any
 *  institution: universities, colleges, in-service teacher training
 *  institutes, research institutes, NGOs and hosting providers.
 */

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager;

use APP\core\Application;
use APP\decision\Decision;
use APP\plugins\generic\conferenceManager\classes\Api\ConferenceApiController;
use APP\plugins\generic\conferenceManager\classes\Api\ConferenceSiteApiController;
use APP\plugins\generic\conferenceManager\classes\Application\DecisionService;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\Decisions\ConferenceAcceptance;
use APP\plugins\generic\conferenceManager\classes\Core\Mail\ConferenceDecisionMailable;
use APP\plugins\generic\conferenceManager\classes\Core\Mail\ConferenceParticipantMailable;
use APP\plugins\generic\conferenceManager\classes\Core\Mail\ConferenceTypeChanged;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway;
use APP\plugins\generic\conferenceManager\classes\Domain\Decision\AcceptanceDecision;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ScholarTags;
use APP\plugins\generic\conferenceManager\classes\Forms\InstitutionSettingsForm;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\classes\Pages\ConferencePageHandler;
use APP\plugins\generic\conferenceManager\classes\Pages\ManageConferenceHandler;
use APP\plugins\generic\conferenceManager\classes\Pages\SiteOverviewHandler;
use APP\plugins\generic\conferenceManager\classes\Tasks\EditionWindowsTask;
use APP\plugins\generic\conferenceManager\classes\Tasks\ReminderTask;
use APP\plugins\generic\conferenceManager\classes\Tasks\RetentionTask;
use APP\plugins\generic\conferenceManager\classes\Wizard\ConferenceWizardStep;
use APP\template\TemplateManager;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Event;
use PKP\core\APIRouter;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\observers\events\DecisionAdded;
use PKP\observers\events\SubmissionSubmitted;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class ConferenceManagerPlugin extends GenericPlugin implements HasTaskScheduler
{
    public const VERB_INSTITUTION = 'institution';
    public const VERB_SITE = 'siteOverview';

    /** Strings of js/workflow.js (keys after "plugins.generic.conferenceManager."). */
    private const WORKFLOW_STRINGS = [
        'panel.title', 'panel.edition', 'panel.type', 'panel.type.none', 'panel.changeType', 'panel.changeType.save',
        'panel.changeType.done', 'panel.participants', 'panel.invoice', 'panel.invoice.none', 'panel.proof', 'panel.proof.none',
        'panel.minor', 'panel.notRegistered', 'panel.paid', 'panel.due', 'panel.error',
    ];

    /** Plugin setting: the journal also takes submissions that are not for an edition. */
    public const SETTING_REGULAR_SUBMISSIONS = 'allowRegularSubmissions';

    /**
     * Hooks are registered unconditionally; each callback checks that the
     * plugin is enabled in the context it works with (plan 7.2, rule 3).
     * Otherwise hooks would be missing in CLI, queue jobs and the scheduler.
     *
     * @param string $category
     * @param string $path
     * @param ?int $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if (Application::isUnderMaintenance()) {
            return true;
        }

        Hook::add('APIHandler::endpoints::plugin', $this->registerApiControllers(...));
        Hook::add('Schema::get::publication', $this->addPublicationProperties(...));
        Hook::add('LoadHandler', $this->loadPageHandler(...));
        Hook::add('TemplateManager::setupBackendPage', $this->addBackendMenu(...));
        Hook::add('TemplateManager::display', $this->addWizardStep(...));
        Hook::add('TemplateManager::display', $this->addWorkflowScript(...));
        Hook::add('TemplateManager::display', $this->addScholarTags(...));
        Hook::add('Templates::Article::Details', $this->addArticleProgram(...));
        Hook::add('Submission::validateSubmit', $this->validateSubmit(...));
        Hook::add('Publication::validatePublish', $this->validatePublish(...));
        Event::listen(SubmissionSubmitted::class, $this->onSubmissionSubmitted(...));
        Event::listen(DecisionAdded::class, $this->onDecisionAdded(...));
        Hook::add('Decision::types', $this->addDecisionTypes(...));
        Hook::add('Workflow::Decisions', $this->addWorkflowDecisions(...));
        Hook::add('Mailer::Mailables', $this->addMailables(...));
        // DAT-06: OJS calls this before it moves the old account's data and deletes it.
        Hook::add('UserAction::mergeUsers', $this->mergeUsers(...));

        return true;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.conferenceManager.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.conferenceManager.description');
    }

    /**
     * @return ConferenceSchemaMigration
     */
    public function getInstallMigration()
    {
        return new ConferenceSchemaMigration();
    }

    public function isEnabledFor(?int $contextId): bool
    {
        return $contextId !== null && (bool) $this->getEnabled($contextId);
    }

    /**
     * OJS 3.5: setEnabled() has no context parameter and works on the
     * current context. Enabling also creates the plugin's user groups (REG-01).
     *
     * @param bool $enabled
     */
    public function setEnabled($enabled)
    {
        parent::setEnabled($enabled);
        $contextId = $this->getCurrentContextId();
        if ($enabled && is_numeric($contextId) && (int) $contextId > 0) {
            $this->ensureUserGroups((int) $contextId);
        }
    }

    /**
     * Idempotent; also run lazily, because the plugin may have been enabled
     * by an older version or directly in the database.
     *
     * @return array<string, int>
     */
    public function ensureUserGroups(int $contextId): array
    {
        try {
            return (new UserGroupGateway($this))->ensure($contextId);
        } catch (\Throwable $e) {
            error_log('conferenceManager: user groups for context ' . $contextId . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Publication properties of the plugin (plan 8.3). Schema::get::*
     * is run with Hook::call(), so the schema arrives as the first item
     * of one array, by reference.
     *
     * @param array{0: \stdClass} $args
     */
    public function addPublicationProperties(string $hookName, array $args): bool
    {
        $schema = &$args[0];
        $schema->properties->conferenceEditionId = (object) [
            'type' => 'integer',
            'apiSummary' => true,
            'validation' => ['nullable', 'min:1'],
        ];
        $schema->properties->presentationType = (object) [
            'type' => 'string',
            'apiSummary' => true,
            'validation' => ['nullable', 'in:oral,poster,online,absentee'],
        ];
        $schema->properties->pageCount = (object) [
            'type' => 'integer',
            'validation' => ['nullable', 'min:0'],
        ];
        return Hook::CONTINUE;
    }

    /**
     * Hook::run() passes its arguments spread, not as one array.
     */
    public function registerApiControllers(string $hookName, APIRouter $router): bool
    {
        $contextId = Application::get()->getRequest()->getContext()?->getId();
        if ($this->isEnabledFor($contextId === null ? null : (int) $contextId)) {
            $this->ensureUserGroups((int) $contextId);
            $router->registerPluginApiControllers([new ConferenceApiController()]);
        } elseif ($contextId === null && $this->isSiteListingEnabled()) {
            $router->registerPluginApiControllers([new ConferenceSiteApiController()]);
        }
        return Hook::CONTINUE;
    }

    /**
     * "Upcoming events" of the whole site (EDN-08) are on when the site
     * administrator enables the plugin at the site level
     * (Administration → Site settings → Plugins).
     */
    public function isSiteListingEnabled(): bool
    {
        return (bool) $this->getSetting(Application::SITE_CONTEXT_ID, 'enabled');
    }

    /**
     * Pages of the plugin. LoadHandler is run with Hook::call():
     * one array of references [&$page, &$op, &$sourceFile, &$handler].
     *
     * @param array{0: string, 1: string, 2: string, 3: mixed} $args
     */
    public function loadPageHandler(string $hookName, array $args): bool
    {
        $page = &$args[0];
        $op = &$args[1];
        $handler = &$args[3];
        $request = Application::get()->getRequest();
        $contextId = $request->getContext()?->getId();

        if ($page === SiteOverviewHandler::PAGE && $contextId === null) {
            $handler = new SiteOverviewHandler($this);
            return true;
        }
        if ($page === ManageConferenceHandler::PAGE && $this->isEnabledFor($contextId === null ? null : (int) $contextId)) {
            $handler = new ManageConferenceHandler($this);
            return true;
        }
        if ($page === ConferencePageHandler::PAGE) {
            if ($contextId === null && $op === 'upcoming' && $this->isSiteListingEnabled()) {
                $handler = new ConferencePageHandler($this);
                return true;
            }
            if ($contextId !== null && in_array($op, ['confirm', 'my', 'invoice', 'receipt', 'certificate', 'verify', 'documents'], true) && $this->isEnabledFor((int) $contextId)) {
                $handler = new ConferencePageHandler($this);
                return true;
            }
            if ($contextId !== null && $op !== 'index' && $this->isEnabledFor((int) $contextId)) {
                $handler = new ConferencePageHandler($this, (string) $op);
                $op = 'view';
                return true;
            }
        }
        return Hook::CONTINUE;
    }

    /**
     * "Conferences" in the backend menu for managers, after "Issues".
     */
    public function addBackendMenu(string $hookName): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if ($context === null && $user !== null && \PKP\security\Validation::isSiteAdmin()) {
            // DST-04: the site administrator's overview, in the site menu.
            $templateMgr = TemplateManager::getManager($request);
            $menu = (array) $templateMgr->getState('menu');
            $menu['conferenceSite'] = [
                'name' => __('plugins.generic.conferenceManager.site.title'),
                'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', SiteOverviewHandler::PAGE),
                'isCurrent' => $request->getRequestedPage() === SiteOverviewHandler::PAGE,
                'icon' => 'Calendar',
            ];
            $templateMgr->setState(['menu' => $menu]);
            return Hook::CONTINUE;
        }
        if ($context === null || $user === null || !$this->isEnabledFor((int) $context->getId())) {
            return Hook::CONTINUE;
        }
        if (!(new UserGroupGateway($this))->canManageRegistrations((int) $context->getId(), (int) $user->getId())) {
            return Hook::CONTINUE;
        }
        $templateMgr = TemplateManager::getManager($request);
        $menu = (array) $templateMgr->getState('menu');
        $item = [
            'name' => __('plugins.generic.conferenceManager.editions.title'),
            'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), ManageConferenceHandler::PAGE),
            'isCurrent' => $request->getRequestedPage() === ManageConferenceHandler::PAGE,
            'icon' => 'Calendar',
        ];
        $index = array_search('settings', array_keys($menu), true);
        $menu = $index === false
            ? $menu + ['conferenceManager' => $item]
            : array_slice($menu, 0, $index, true) + ['conferenceManager' => $item] + array_slice($menu, $index, null, true);
        $templateMgr->setState(['menu' => $menu]);
        return Hook::CONTINUE;
    }

    /**
     * PUB-02: a paper of a conference edition is a conference paper for
     * Google Scholar: citation_conference_title instead of
     * citation_journal_title, the ISBN of the proceedings and the
     * institution as publisher. Runs after the Google Scholar plugin has
     * added its tags and replaces them by the same header names.
     */
    public function addScholarTags(string $hookName, array $args): bool
    {
        if ($args[1] !== 'frontend/pages/article.tpl') {
            return Hook::CONTINUE;
        }
        $templateMgr = $args[0];
        $context = Application::get()->getRequest()->getContext();
        $publication = $templateMgr->getTemplateVars('publication');
        $editionId = (int) ($publication?->getData('conferenceEditionId') ?? 0);
        if ($context === null || $editionId === 0 || !$this->isEnabledFor((int) $context->getId())) {
            return Hook::CONTINUE;
        }
        try {
            $info = ContextGateway::fromContext($context);
            $edition = ServiceFactory::editions()->get($info, $editionId);
            if ($edition === null) {
                return Hook::CONTINUE;
            }
            $locale = (string) ($publication->getData('locale') ?: $info->primaryLocale);
            $publisher = (string) ServiceFactory::institutionProfiles()->getOrDraft($info)->text('name')->resolve($info->primaryLocale, $info->primaryLocale);
            foreach (ScholarTags::of($edition, $locale, $info->primaryLocale, $publisher) as $name => $html) {
                $templateMgr->addHeader($name, $html);
            }
        } catch (\Throwable $e) {
            error_log('conferenceManager: scholar tags: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * PRG-08: the time and room of the paper in the program of the
     * conference, on the article page, once the program is public.
     *
     * @param array{0: array<mixed>, 1: \Smarty_Internal_Template|TemplateManager, 2: string} $args
     */
    public function addArticleProgram(string $hookName, array $args): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $templateMgr = TemplateManager::getManager($request);
        $publication = $templateMgr->getTemplateVars('publication');
        $editionId = (int) ($publication?->getData('conferenceEditionId') ?? 0);
        if ($context === null || $editionId === 0 || !$this->isEnabledFor((int) $context->getId())) {
            return Hook::CONTINUE;
        }
        try {
            $info = ContextGateway::fromContext($context);
            $edition = ServiceFactory::editions()->get($info, $editionId);
            if ($edition === null || !\APP\plugins\generic\conferenceManager\classes\Application\ProgramService::isPublic($edition)) {
                return Hook::CONTINUE;
            }
            $locale = \PKP\facades\Locale::getLocale();
            $place = ServiceFactory::schedule()->ofSubmission($info, $edition, (int) $publication->getData('submissionId'), $locale);
            if ($place === null) {
                return Hook::CONTINUE;
            }
            $date = new \DateTimeImmutable($place['date'] . ' 12:00', new \DateTimeZone($edition->timezone));
            $day = class_exists(\IntlDateFormatter::class) ? (new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $edition->timezone))->format($date) : false;
            $url = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'conference', $edition->path, ['program'], null, 'cm-session-' . $place['sessionId']);
            $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $args[2] .= '<section class="item cmArticleProgram"><h2 class="label">' . $e(__('plugins.generic.conferenceManager.program.article.title')) . '</h2>'
                . '<p><time datetime="' . $e($place['startsAt']) . '">' . $e((is_string($day) ? $day : $place['date']) . ', ' . $place['start'] . '–' . $place['end']) . '</time>'
                . ' (' . $e($edition->timezone) . ')</p>'
                . '<p><a href="' . $e($url) . '">' . $e($place['title']) . '</a>' . ($place['room'] !== null ? ' · ' . $e($place['room']) : '') . '</p>'
                . '<p>' . $e((string) $edition->text('name')->resolve($locale, $info->primaryLocale)) . '</p></section>';
        } catch (\Throwable $ex) {
            error_log('conferenceManager: article program: ' . $ex->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * DEC-01, DEC-05, DEC-06: decision buttons and the conference panel in
     * the submission card, opened from the editorial dashboard.
     *
     * @param array{0: TemplateManager, 1: string} $args
     */
    public function addWorkflowScript(string $hookName, array $args): bool
    {
        if ($args[1] !== 'dashboard/editors.tpl') {
            return Hook::CONTINUE;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if ($context === null || $user === null || !$this->isEnabledFor((int) $context->getId())
            || !$user->hasRole([\PKP\security\Role::ROLE_ID_MANAGER, \PKP\security\Role::ROLE_ID_SUB_EDITOR, \PKP\security\Role::ROLE_ID_SITE_ADMIN], (int) $context->getId())) {
            return Hook::CONTINUE;
        }
        $templateMgr = $args[0];
        $base = $request->getBaseUrl() . '/' . $this->getPluginPath();
        $config = [
            'apiUrl' => $request->getDispatcher()->url($request, Application::ROUTE_API, $context->getPath(), 'conference'),
            'csrfToken' => $request->getSession()->token(),
            'codes' => array_map(static fn (AcceptanceDecision $d): int => $d->value, AcceptanceDecision::cases()),
            'i18n' => array_combine(self::WORKFLOW_STRINGS, array_map(static fn (string $k): string => __('plugins.generic.conferenceManager.' . $k), self::WORKFLOW_STRINGS)),
        ];
        $templateMgr->addJavaScript('conferenceWorkflowConfig', 'window.conferenceManagerWorkflow = ' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';', [
            'inline' => true, 'contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
        ]);
        $templateMgr->addJavaScript('conferenceWorkflow', $base . '/js/workflow.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addStyleSheet('conferenceWorkflow', $base . '/css/editionsManager.css', ['contexts' => 'backend']);
        return Hook::CONTINUE;
    }

    public function regularSubmissionsAllowed(int $contextId): bool
    {
        return (bool) $this->getSetting($contextId, self::SETTING_REGULAR_SUBMISSIONS);
    }

    /**
     * REG-02: the "Conference participation" step of the submission wizard.
     * TemplateManager::display is run with Hook::call(): [$templateMgr, &$template, &$output].
     *
     * @param array{0: TemplateManager, 1: string, 2: ?string} $args
     */
    public function addWizardStep(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        if ($args[1] !== 'submission/wizard.tpl') {
            return Hook::CONTINUE;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $submission = $templateMgr->getTemplateVars('submission');
        if ($context === null || !$submission instanceof \APP\submission\Submission || !$this->isEnabledFor((int) $context->getId())) {
            return Hook::CONTINUE;
        }
        try {
            $info = ContextGateway::fromContext($context);
            $service = ServiceFactory::registrations();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $submissionId = (int) $submission->getId();
            if (!$service->isStepNeeded($info, $submissionId, $now)) {
                return Hook::CONTINUE;
            }
            $locale = \PKP\facades\Locale::getLocale();
            $regular = $this->regularSubmissionsAllowed($info->id);
            $consent = ServiceFactory::consentText($info, $locale);
            $open = $service->openEditions($info, $now);
            $step = new ConferenceWizardStep($service);
            $registration = $step->prefill($info, $submissionId, $open, $request->getUser()?->getEmail(), $consent->version, $now, $regular);

            $editions = $open;
            $chosen = $registration->editionId;
            if ($chosen !== null && !in_array($chosen, array_map(static fn (Edition $e): ?int => $e->id, $open), true)) {
                $current = ServiceFactory::editions()->get($info, $chosen);
                if ($current !== null) {
                    $editions[] = $current;
                }
            }
            $locales = array_values(array_unique(array_merge([(string) $submission->getData('locale')], $info->formLocales)));
            $config = $step->build($info, $submissionId, $editions, $registration, $consent, $locale, $locales, $regular);
            $config['reviewTemplate'] = $this->getTemplateResource('wizardReview.tpl');

            $templateMgr->setState(['steps' => ConferenceWizardStep::insert((array) $templateMgr->getState('steps'), $config)]);
            $reviewSteps = array_values(array_filter(
                (array) $templateMgr->getTemplateVars('reviewSteps'),
                static fn ($s): bool => ($s['id'] ?? null) !== ConferenceWizardStep::STEP_ID,
            ));
            $reviewSteps[] = ['id' => $config['id'], 'reviewTemplate' => $config['reviewTemplate'], 'reviewName' => $config['reviewName']];
            $templateMgr->assign([
                'reviewSteps' => $reviewSteps,
                'conferenceReviewLabels' => [
                    'none' => __('common.noneProvided'),
                    'consentGiven' => __('plugins.generic.conferenceManager.registration.consent.given'),
                    'consentMissing' => __('plugins.generic.conferenceManager.registration.consent.missing'),
                ],
            ]);
            $templateMgr->addJavaScript(
                'conferenceWizardReview',
                $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/wizardReview.js',
                ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST],
            );
        } catch (\Throwable $e) {
            error_log('conferenceManager: wizard step: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * EDN-03, REG-02: no submission outside the call window, and none
     * without the questionnaire and the consent.
     * Submission::validateSubmit is run with Hook::call(): [&$errors, $submission, $context].
     *
     * @param array{0: array<string, mixed>, 1: \APP\submission\Submission, 2: \PKP\context\Context} $args
     */
    public function validateSubmit(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $submission = $args[1];
        $context = $args[2];
        if (!$this->isEnabledFor((int) $context->getId())) {
            return Hook::CONTINUE;
        }
        try {
            $info = ContextGateway::fromContext($context);
            $found = ServiceFactory::registrations()->validateSubmit(
                $info,
                (int) $submission->getId(),
                ServiceFactory::consentText($info, \PKP\facades\Locale::getLocale())->version,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                $this->regularSubmissionsAllowed($info->id),
            );
            foreach ($found->toArray() as $field => $keys) {
                $errors[$field] = array_map(static fn (string $k): string => __($k), $keys);
            }
        } catch (\Throwable $e) {
            error_log('conferenceManager: validate submit: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * FEE-11: a conference material whose fee is not paid is not published.
     * Publication::validatePublish is run with Hook::call():
     * [&$errors, $publication, $submission, $allowedLocales, $primaryLocale].
     *
     * @param array{0: array<string, string>, 1: \APP\publication\Publication, 2: \APP\submission\Submission} $args
     */
    public function validatePublish(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $submission = $args[2];
        $contextId = (int) $submission->getData('contextId');
        if (!$this->isEnabledFor($contextId)) {
            return Hook::CONTINUE;
        }
        try {
            $invoice = ServiceFactory::invoices()->unpaidForPublication((int) $submission->getId());
            if ($invoice !== null) {
                $due = $invoice->due();
                $errors['conferencePayment'] = __('plugins.generic.conferenceManager.publish.unpaid', [
                    'number' => (string) $invoice->number,
                    'due' => $due->decimal() . ' ' . $due->currency,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('conferenceManager: validate publish: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * REG-03, REG-04: authors become participants, co-authors get a letter.
     */
    public function onSubmissionSubmitted(SubmissionSubmitted $event): void
    {
        if (!$this->isEnabledFor((int) $event->context->getId())) {
            return;
        }
        try {
            ServiceFactory::registrations()->onSubmitted(
                ContextGateway::fromContext($event->context),
                (int) $event->submission->getId(),
                Application::get()->getRequest()->getUser()?->getId(),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (\Throwable $e) {
            error_log('conferenceManager: after submit: ' . $e->getMessage());
        }
    }

    /**
     * DEC-02, FEE-04: after an acceptance (901–904 or the core ones) the
     * presentation type, the issue of the edition and the invoice.
     */
    public function onDecisionAdded(DecisionAdded $event): void
    {
        $decision = $event->decisionType->getDecision();
        if (!DecisionService::isAcceptance($decision) || !$this->isEnabledFor((int) $event->context->getId())) {
            return;
        }
        try {
            ServiceFactory::decisions()->onDecision(
                ContextGateway::fromContext($event->context),
                (int) $event->submission->getId(),
                $decision,
                $event->editor->getId(),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (\Throwable $e) {
            error_log('conferenceManager: after decision: ' . $e->getMessage());
        }
    }

    /**
     * DEC-01: decisions 901–904 are known to OJS in every context, so a
     * recorded decision can always be shown; they are offered only where
     * the plugin is enabled (addWorkflowDecisions).
     *
     * @param array{0: \Illuminate\Support\Collection<int, \PKP\decision\DecisionType>} $args
     */
    public function addDecisionTypes(string $hookName, array $args): bool
    {
        foreach (AcceptanceDecision::cases() as $d) {
            $args[0]->push(new ConferenceAcceptance($d));
        }
        return Hook::CONTINUE;
    }

    /**
     * DEC-01: the buttons in the submission stage next to "Accept and skip
     * review", for editors who may take that decision. The workflow script
     * shows them only for submissions of an edition.
     *
     * @param array{0: array<int, \PKP\decision\DecisionType>, 1: int} $args
     */
    public function addWorkflowDecisions(string $hookName, array $args): bool
    {
        $context = Application::get()->getRequest()->getContext();
        if ($context === null || !$this->isEnabledFor((int) $context->getId()) || (int) $args[1] !== WORKFLOW_STAGE_ID_SUBMISSION) {
            return Hook::CONTINUE;
        }
        $types = &$args[0];
        $canSkip = false;
        foreach ($types as $type) {
            $canSkip = $canSkip || $type->getDecision() === Decision::SKIP_EXTERNAL_REVIEW;
        }
        if ($canSkip) {
            foreach (AcceptanceDecision::cases() as $d) {
                $types[] = new ConferenceAcceptance($d);
            }
        }
        return Hook::CONTINUE;
    }

    /**
     * The letters of the decisions and of a type change in Settings → Emails.
     *
     * @param array{0: \Illuminate\Support\Collection<int, class-string>, 1: \PKP\context\Context} $args
     */
    public function addMailables(string $hookName, array $args): bool
    {
        if (!$this->isEnabledFor((int) $args[1]->getId())) {
            return Hook::CONTINUE;
        }
        foreach ([...ConferenceDecisionMailable::classes(), ConferenceTypeChanged::class, ...ConferenceParticipantMailable::classes()] as $class) {
            $args[0]->push($class);
        }
        return Hook::CONTINUE;
    }

    /**
     * EDN-03: close calls whose deadline has passed, every 15 minutes.
     */
    /**
     * DAT-06: the plugin's records of the old account move to the new one.
     *
     * @param string $hookName
     * @param array{0: int, 1: int} $args
     */
    public function mergeUsers($hookName, $args): bool
    {
        [$oldUserId, $newUserId] = [(int) $args[0], (int) $args[1]];
        if ($oldUserId > 0 && $newUserId > 0 && \Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('conf_participants')) {
            ServiceFactory::personalData()->mergeUsers($oldUserId, $newUserId);
        }
        return Hook::CONTINUE;
    }

    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler->addSchedule(new EditionWindowsTask())
            ->everyFifteenMinutes()
            // OJS 3.5.0-5 does not name the event itself; withoutOverlapping() needs a name.
            ->name(EditionWindowsTask::class)
            ->withoutOverlapping();
        $scheduler->addSchedule(new ReminderTask())
            ->hourly()
            ->name(ReminderTask::class)
            ->withoutOverlapping();
        $scheduler->addSchedule(new RetentionTask())
            ->daily()
            ->name(RetentionTask::class)
            ->withoutOverlapping();
    }

    /**
     * @param \PKP\core\PKPRequest $request
     * @param array $verb
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        $actions = parent::getActions($request, $verb);
        if (!$request->getContext()) {
            // DST-04: the site administrator's overview of all journals.
            if (\PKP\security\Validation::isSiteAdmin()) {
                array_unshift($actions, new LinkAction(
                    self::VERB_SITE,
                    new \PKP\linkAction\request\RedirectAction($request->getDispatcher()->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, 'index', SiteOverviewHandler::PAGE)),
                    __('plugins.generic.conferenceManager.site.title'),
                    null,
                ));
            }
            return $actions;
        }
        if (!$this->getEnabled()) {
            return $actions;
        }
        array_unshift($actions, new LinkAction(
            self::VERB_INSTITUTION,
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, [
                    'verb' => self::VERB_INSTITUTION,
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                ]),
                __('plugins.generic.conferenceManager.institution.title'),
            ),
            __('plugins.generic.conferenceManager.institution.title'),
            null,
        ));
        return $actions;
    }

    /**
     * @param array $args
     * @param \PKP\core\PKPRequest $request
     */
    public function manage($args, $request)
    {
        $context = $request->getContext();
        if ($context === null || $request->getUserVar('verb') !== self::VERB_INSTITUTION) {
            return parent::manage($args, $request);
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->registerPlugin('function', 'plugin_url', $this->smartyPluginUrl(...));

        $form = new InstitutionSettingsForm(
            $this,
            ContextGateway::fromContext($context),
            ServiceFactory::institutionProfiles(),
            ServiceFactory::jurisdictions(),
        );

        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                if ($form->isSaved()) {
                    return new JSONMessage(true);
                }
            }
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->initData();
        return new JSONMessage(true, $form->fetch($request));
    }
}
