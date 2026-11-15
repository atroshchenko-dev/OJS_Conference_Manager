<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Pages;

use APP\handler\Handler;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Core\SiteOverviewGateway;
use APP\plugins\generic\conferenceManager\classes\Forms\SiteLoadForm;
use APP\plugins\generic\conferenceManager\ConferenceManagerPlugin;
use APP\template\TemplateManager;
use PKP\security\authorization\PKPSiteAccessPolicy;
use PKP\security\Role;

/**
 * DST-04: site page `/index/conferenceSite` for the site administrator:
 * all journals with the plugin and the load profile (plan, 15.2). A site
 * page, because OJS lets the site plugin grid manage site plugins only.
 */
class SiteOverviewHandler extends Handler
{
    public const PAGE = 'conferenceSite';

    /** @var bool */
    public $_isBackendPage = true;

    public function __construct(private readonly ConferenceManagerPlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment([Role::ROLE_ID_SITE_ADMIN], ['index', 'csv']);
    }

    /**
     * @param \APP\core\Request $request
     * @param array $args
     * @param array $roleAssignments
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new PKPSiteAccessPolicy($request, ['index', 'csv'], $roleAssignments));
        return $request->getContext() === null && parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function index($args, $request)
    {
        $this->setupTemplate($request);
        $form = new SiteLoadForm($this->plugin, ServiceFactory::loadPolicy(), (new SiteOverviewGateway())->overview(), $request->getUser()?->getId());
        $saved = false;
        if ($request->isPost()) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                $saved = true;
            }
        } else {
            $form->initData();
        }
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pageTitle' => __('plugins.generic.conferenceManager.site.title'),
            'siteFormHtml' => $form->fetch($request),
            'siteSaved' => $saved,
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('sitePage.tpl'));
    }

    /**
     * DST-04: the journals table as CSV.
     *
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function csv($args, $request)
    {
        $k = 'plugins.generic.conferenceManager.site.';
        $header = [
            'path' => 'path', 'name' => __($k . 'journal'), 'enabled' => __($k . 'enabled'),
            'editions' => __($k . 'allEditions'), 'activeEditions' => __($k . 'activeEditions'), 'participants' => __($k . 'participants'),
            'submissions' => __($k . 'submissions'), 'paidInvoices' => __($k . 'paidInvoices'), 'certificates' => __($k . 'certificates'),
            'pendingLetters' => __($k . 'letters'), 'queuedJobs' => __($k . 'jobs'),
        ];
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="conference-site-' . gmdate('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        echo SiteOverviewGateway::csv((new SiteOverviewGateway())->overview(), $header);
        exit;
    }
}
