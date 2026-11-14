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
use APP\plugins\generic\conferenceManager\classes\Api\InstitutionController;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Forms\InstitutionSettingsForm;
use APP\plugins\generic\conferenceManager\classes\Migrations\ConferenceSchemaMigration;
use APP\template\TemplateManager;
use PKP\core\APIRouter;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class ConferenceManagerPlugin extends GenericPlugin
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
     * Hook::run() passes its arguments spread, not as one array.
     */
    public function registerApiControllers(string $hookName, APIRouter $router): bool
    {
        $contextId = Application::get()->getRequest()->getContext()?->getId();
        if ($this->isEnabledFor($contextId === null ? null : (int) $contextId)) {
            $router->registerPluginApiControllers([new InstitutionController()]);
        }
        return Hook::CONTINUE;
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
