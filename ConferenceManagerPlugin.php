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
use APP\plugins\generic\conferenceManager\classes\Api\ConferenceApiController;
use APP\plugins\generic\conferenceManager\classes\Api\ConferenceSiteApiController;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway;
use APP\plugins\generic\conferenceManager\classes\Forms\InstitutionSettingsForm;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\plugins\generic\conferenceManager\classes\Pages\ConferencePageHandler;
use APP\plugins\generic\conferenceManager\classes\Pages\ManageConferenceHandler;
use APP\plugins\generic\conferenceManager\classes\Tasks\EditionWindowsTask;
use APP\template\TemplateManager;
use PKP\core\APIRouter;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use PKP\security\Role;

class ConferenceManagerPlugin extends GenericPlugin implements HasTaskScheduler
{
    public const VERB_INSTITUTION = 'institution';

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

        if ($page === ManageConferenceHandler::PAGE && $this->isEnabledFor($contextId === null ? null : (int) $contextId)) {
            $handler = new ManageConferenceHandler($this);
            return true;
        }
        if ($page === ConferencePageHandler::PAGE) {
            if ($contextId === null && $op === 'upcoming' && $this->isSiteListingEnabled()) {
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
        if ($context === null || $user === null || !$this->isEnabledFor((int) $context->getId())) {
            return Hook::CONTINUE;
        }
        if (!$user->hasRole([Role::ROLE_ID_MANAGER], (int) $context->getId()) && !$user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID)) {
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
     * EDN-03: close calls whose deadline has passed, every 15 minutes.
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler->addSchedule(new EditionWindowsTask())
            ->everyFifteenMinutes()
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
        if (!$this->getEnabled() || !$request->getContext()) {
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
