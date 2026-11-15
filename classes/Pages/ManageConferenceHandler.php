<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Pages;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventType;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\PublicationMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ReviewMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use APP\plugins\generic\conferenceManager\ConferenceManagerPlugin;
use APP\template\TemplateManager;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

/**
 * Backend page "Conferences" (/{context}/manageConference): the list and
 * the form of editions (EDN-01…05, 09). The page is a Vue component of the
 * plugin (js/editionsManager.js) working with the plugin's REST API.
 */
class ManageConferenceHandler extends Handler
{
    public const PAGE = 'manageConference';

    /** @var bool */
    public $_isBackendPage = true;

    public function __construct(private readonly ConferenceManagerPlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], ['index']);
    }

    /**
     * @param \APP\core\Request $request
     * @param array $args
     * @param array $roleAssignments
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function index($args, $request)
    {
        $this->setupTemplate($request);
        $context = $request->getContext();
        $info = ContextGateway::fromContext($context);
        $dispatcher = $request->getDispatcher();
        $templateMgr = TemplateManager::getManager($request);

        $base = $request->getBaseUrl() . '/' . $this->plugin->getPluginPath();
        $templateMgr->addJavaScript('conferenceEditionsManager', $base . '/js/editionsManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addStyleSheet('conferenceEditionsManager', $base . '/css/editionsManager.css', ['contexts' => 'backend']);

        $names = $context->getSupportedFormLocaleNames();
        $locales = [];
        foreach ($info->formLocales as $locale) {
            $locales[] = ['key' => $locale, 'label' => $names[$locale] ?? $locale];
        }
        $choices = static fn (array $cases): array => array_map(
            static fn ($c): array => ['value' => $c->value, 'label' => __($c->labelKey())],
            $cases,
        );
        $eventTypes = [];
        foreach (EventType::cases() as $type) {
            $eventTypes[] = [
                'value' => $type->value,
                'label' => __($type->labelKey()),
                'defaultSubmissionMode' => $type->defaultSubmissionMode()->value,
                'defaultPublicationMode' => $type->defaultPublicationMode()->value,
            ];
        }

        $templateMgr->setState(['components' => ['conferenceEditions' => [
            'apiUrl' => $dispatcher->url($request, Application::ROUTE_API, $context->getPath(), 'conference/editions'),
            'publicUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), ConferencePageHandler::PAGE, '__path__'),
            'issueUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'manageIssues'),
            'csrfToken' => $request->getSession()->token(),
            'locales' => $locales,
            'primaryLocale' => $info->primaryLocale,
            'uiLocale' => \PKP\facades\Locale::getLocale(),
            'defaultTimezone' => ServiceFactory::editions()->defaultTimezone($info),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'localizedFields' => Edition::LOCALIZED_FIELDS,
            'dateFields' => array_keys(Edition::DATE_FIELDS),
            'options' => [
                'eventTypes' => $eventTypes,
                'statuses' => $choices(EditionStatus::cases()),
                'submissionModes' => $choices(SubmissionMode::cases()),
                'publicationModes' => $choices(PublicationMode::cases()),
                'formats' => $choices(EventFormat::cases()),
                'reviewModes' => $choices(ReviewMode::cases()),
            ],
            'i18n' => self::strings(),
        ]]]);

        $templateMgr->assign(['pageTitle' => __('plugins.generic.conferenceManager.editions.title')]);
        return $templateMgr->display($this->plugin->getTemplateResource('manageEditions.tpl'));
    }

    /**
     * Interface strings of the Vue component, translated on the server.
     *
     * @return array<string, string>
     */
    private static function strings(): array
    {
        $out = [];
        foreach ([
            'title', 'new', 'empty', 'edit', 'copy', 'delete', 'deleteConfirm', 'deleted', 'saved', 'save', 'cancel',
            'publicPage', 'issue', 'filterStatus', 'allStatuses', 'loading', 'errors', 'copyOf',
            'section.main', 'section.modes', 'section.dates', 'section.texts', 'section.details',
            'field.name', 'field.path', 'field.path.description', 'field.eventType', 'field.format', 'field.timezone',
            'field.venue', 'field.description', 'field.learningOutcomes', 'field.cfpText', 'field.status',
            'field.submissionMode', 'field.publicationMode', 'field.externalProceedingsUrl', 'field.reviewMode',
            'field.startsAt', 'field.endsAt', 'field.cfpOpensAt', 'field.abstractDeadline', 'field.paperDeadline',
            'field.registrationClosesAt', 'field.paymentDeadline', 'field.dates.description',
            'field.isbn', 'field.udc', 'field.vectorProgramId', 'field.hours', 'field.ects',
            'column.dates', 'column.status', 'column.type', 'submissionsOpen', 'submissionsClosed',
        ] as $key) {
            $out[$key] = __('plugins.generic.conferenceManager.editions.' . $key);
        }
        return $out;
    }
}
