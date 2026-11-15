<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Pages;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\IssueGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionWindows;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventType;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\PublicationMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use APP\plugins\generic\conferenceManager\ConferenceManagerPlugin;
use APP\template\TemplateManager;
use DateTimeImmutable;
use DateTimeZone;
use PKP\core\PKPRequest;
use PKP\facades\Locale;

/**
 * Public pages (plan 9):
 *   /{context}/conference/{edition}   page of an edition (EDN-07)
 *   /index/conference/upcoming        upcoming events of all journals (EDN-08, DST-03)
 *   /{context}/conference/confirm/{token}  a co-author confirms participation (REG-04)
 *   /{context}/conference/{edition}/register  a listener registers (REG-05)
 *   /{context}/conference/my          the participant's cabinet (REG-06, REG-08)
 *
 * The router calls the operation "view" or "upcoming"; the requested
 * edition path is given to the constructor by the LoadHandler hook.
 */
class ConferencePageHandler extends Handler
{
    public const PAGE = 'conference';

    public function __construct(private readonly ConferenceManagerPlugin $plugin, private readonly string $editionPath = '')
    {
        parent::__construct();
    }

    /**
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function view($args, $request)
    {
        $context = $request->getContext();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $info = ContextGateway::fromContext($context);
        $edition = ServiceFactory::editions()->getByPath($info, $this->editionPath);
        // Drafts are visible only to managers (for preview).
        if ($edition === null || ($edition->status === EditionStatus::Draft && !$this->canManage($request))) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        if (($args[0] ?? null) === 'register') {
            return $this->register($edition, $info, $request);
        }
        if (isset($args[0])) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $locale = Locale::getLocale();
        $vm = $this->viewModel($edition, $locale, $info->primaryLocale, $request);
        $templateMgr->addStyleSheet('conferenceEditionPage', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/public.css', ['contexts' => 'frontend']);
        $templateMgr->addHeader('conferenceEditionJsonLd', '<script type="application/ld+json">'
            . json_encode($this->jsonLd($edition, $vm, $request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
            . '</script>', ['contexts' => 'frontend']);
        if ($edition->status === EditionStatus::Draft) {
            $templateMgr->addHeader('conferenceEditionNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        }
        $templateMgr->assign([
            'edition' => $vm,
            'pageTitleTranslated' => $vm['name'],
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/edition.tpl'));
    }

    /**
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function upcoming($args, $request)
    {
        if ($request->getContext() !== null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $filters = [
            'contextId' => (int) $request->getUserVar('journal') ?: null,
            'eventType' => EventType::tryFrom((string) $request->getUserVar('type'))?->value,
            'format' => EventFormat::tryFrom((string) $request->getUserVar('format'))?->value,
            'q' => mb_substr(trim((string) $request->getUserVar('q')), 0, 100),
        ];
        $page = max(1, (int) $request->getUserVar('page'));
        $perPage = 20;
        $query = ServiceFactory::upcoming();
        $result = $query->find($filters, $perPage, ($page - 1) * $perPage);

        $locale = Locale::getLocale();
        $contexts = [];
        $contextDao = Application::getContextDAO();
        foreach ($query->contextIds() as $id) {
            $context = $contextDao->getById($id);
            if ($context !== null) {
                $contexts[$id] = ['id' => $id, 'name' => (string) $context->getLocalizedName(), 'path' => $context->getPath(), 'primaryLocale' => $context->getPrimaryLocale()];
            }
        }
        $items = [];
        foreach ($result['items'] as $edition) {
            $c = $contexts[$edition->contextId] ?? null;
            if ($c === null) {
                continue;
            }
            $vm = $this->viewModel($edition, $locale, $c['primaryLocale'], $request, $c['path']);
            $vm['journal'] = $c['name'];
            $items[] = $vm;
        }

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addStyleSheet('conferenceEditionPage', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/public.css', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'pageTitle' => 'plugins.generic.conferenceManager.upcoming.title',
            'items' => $items,
            'itemsMax' => $result['itemsMax'],
            'page' => $page,
            'pages' => (int) ceil($result['itemsMax'] / $perPage),
            'filters' => $filters,
            'journals' => array_values($contexts),
            'eventTypes' => array_map(static fn (EventType $t): array => ['value' => $t->value, 'label' => __($t->labelKey())], EventType::cases()),
            'formats' => array_map(static fn (EventFormat $t): array => ['value' => $t->value, 'label' => __($t->labelKey())], EventFormat::cases()),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/upcoming.tpl'));
    }

    /**
     * REG-04: the link from the letter. GET shows the question, POST
     * records the answer; the link works once and for 30 days. No account
     * is needed: the token itself is the authorization.
     *
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function confirm($args, $request)
    {
        $context = $request->getContext();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $token = (string) ($args[0] ?? '');
        $service = ServiceFactory::registrations();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $contextId = (int) $context->getId();

        $retry = false;
        if ($request->isPost() && $request->checkCSRF()) {
            $state = $service->answer($contextId, $token, $request->getUserVar('answer') === 'accept', $now, $request->getRemoteAddr());
        } else {
            // A form without a valid CSRF token (e.g. an expired session)
            // records nothing and asks again.
            $retry = $request->isPost();
            $state = $service->inspectToken($contextId, $token, $now);
        }

        $info = ContextGateway::fromContext($context);
        $edition = isset($state['editionId']) ? ServiceFactory::editions()->get($info, (int) $state['editionId']) : null;
        $locale = Locale::getLocale();

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addStyleSheet('conferenceEditionPage', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/public.css', ['contexts' => 'frontend']);
        // The address holds the token: keep it out of search engines and referrers.
        $templateMgr->addHeader('conferenceConfirmNoIndex', '<meta name="robots" content="noindex"><meta name="referrer" content="no-referrer">', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'confirmState' => $state['result'],
            'confirmRetry' => $retry,
            'confirmEmail' => $state['email'] ?? '',
            'confirmToken' => $state['result'] === 'pending' ? $token : '',
            'confirmEditionName' => $edition?->text('name')->resolve($locale, $info->primaryLocale) ?? '',
            'confirmEditionUrl' => $edition === null || $edition->status === EditionStatus::Draft ? '' : $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, $edition->path),
            'pageTitle' => 'plugins.generic.conferenceManager.confirm.title',
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/confirm.tpl'));
    }

    /**
     * REG-05: the form of a listener. The person must be logged in (the
     * account gives the name and email); spam is kept out by the login,
     * the CSRF token, a hidden field that people leave empty, and one
     * active registration per person and edition.
     *
     * @param \APP\core\Request $request
     */
    private function register(Edition $edition, \APP\plugins\generic\conferenceManager\classes\Application\ContextInfo $info, $request): mixed
    {
        $user = $request->getUser();
        $context = $request->getContext();
        $dispatcher = $request->getDispatcher();
        $registerUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, $edition->path, ['register']);
        if ($user === null) {
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'login', null, null, ['source' => $registerUrl]));
        }
        $locale = Locale::getLocale();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $service = ServiceFactory::participants();
        $consent = ServiceFactory::consentText($info, $locale);
        $person = (new \APP\plugins\generic\conferenceManager\classes\Core\PersonGateway())->of((int) $user->getId(), $locale);

        $errors = [];
        $values = [
            'categoryId' => (string) $request->getUserVar('categoryId'),
            'participationForm' => (string) $request->getUserVar('participationForm'),
            'affiliation' => mb_substr(trim((string) ($request->isPost() ? $request->getUserVar('affiliation') : ($person['affiliation'] ?? ''))), 0, 255),
            'position' => mb_substr(trim((string) $request->getUserVar('position')), 0, 255),
            'consent' => (bool) $request->getUserVar('consent'),
        ];
        if ($request->isPost()) {
            if (!$request->checkCSRF()) {
                $errors['form'] = [__('plugins.generic.conferenceManager.confirm.retry')];
            } elseif (trim((string) $request->getUserVar('website')) !== '') {
                // The hidden field is filled only by robots.
                $errors['form'] = [__('plugins.generic.conferenceManager.listener.error.spam')];
            } elseif ($person !== null) {
                $person['affiliation'] = $values['affiliation'] === '' ? null : $values['affiliation'];
                $person['position'] = $values['position'] === '' ? null : $values['position'];
                [$result, $validation] = $service->registerListener($info, (int) $edition->id, $person, $values, $consent->version, $now, $request->getRemoteAddr());
                if ($result !== null) {
                    $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my', null, ['registered' => $result['participationId']]));
                }
                foreach ($validation->toArray() as $field => $keys) {
                    $errors[$field] = array_map(static fn (string $k): string => __($k), $keys);
                }
            }
        }

        $forms = array_map(static fn ($f): array => ['value' => $f->value, 'label' => __($f->labelKey())], ParticipantService::listenerForms($edition));
        if ($values['participationForm'] === '' && count($forms) === 1) {
            $values['participationForm'] = $forms[0]['value'];
        }
        $categories = [];
        foreach (ServiceFactory::fees()->categories($info, (int) $edition->id) ?? [] as $category) {
            $categories[] = ['value' => $category->id, 'label' => (string) $category->name->resolve($locale, $info->primaryLocale), 'requiresProof' => $category->requiresProof];
        }

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addStyleSheet('conferenceEditionPage', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/public.css', ['contexts' => 'frontend']);
        $templateMgr->addHeader('conferenceRegisterNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $vm = $this->viewModel($edition, $locale, $info->primaryLocale, $request);
        $templateMgr->assign([
            'edition' => $vm,
            'registerOpen' => EditionWindows::registrationOpenAt($edition, $now),
            'registerUrl' => $registerUrl,
            'myUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my'),
            'registerErrors' => $errors,
            'registerValues' => $values,
            'registerForms' => $forms,
            'registerCategories' => $categories,
            'registerPerson' => [
                'name' => $person === null ? '' : trim(($person['givenName'][$locale] ?? (array_values($person['givenName'])[0] ?? '')) . ' ' . ($person['familyName'][$locale] ?? (array_values($person['familyName'])[0] ?? ''))),
                'email' => $person['email'] ?? '',
            ],
            'consentHtml' => nl2br(htmlspecialchars($consent->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false),
            'pageTitleTranslated' => __('plugins.generic.conferenceManager.listener.title') . ': ' . $vm['name'],
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/register.tpl'));
    }

    /**
     * REG-06, REG-08: the person's own participations in this journal and
     * cancellation before the deadline.
     *
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function my($args, $request)
    {
        $context = $request->getContext();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $dispatcher = $request->getDispatcher();
        $myUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my');
        $user = $request->getUser();
        if ($user === null) {
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'login', null, null, ['source' => $myUrl]));
        }
        $info = ContextGateway::fromContext($context);
        $locale = Locale::getLocale();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $service = ServiceFactory::participants();
        $email = (string) $user->getEmail();

        $message = null;
        if ($request->getUserVar('registered')) {
            $message = ['type' => 'status', 'text' => __('plugins.generic.conferenceManager.listener.done')];
        }
        if ($request->isPost()) {
            if (!$request->checkCSRF()) {
                $message = ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.confirm.retry')];
            } else {
                $out = $service->cancel($info, (int) $request->getUserVar('participationId'), (int) $user->getId(), $email, false, $now, $request->getRemoteAddr());
                if ($out['result'] === ParticipantService::CANCELLED) {
                    $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my', null, ['cancelled' => 1]));
                }
                $message = ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.participation.error.' . $out['result'])];
            }
        } elseif ($request->getUserVar('cancelled')) {
            $message = ['type' => 'status', 'text' => __('plugins.generic.conferenceManager.participation.cancelled')];
        }

        $items = [];
        foreach ($service->myParticipations($info, (int) $user->getId(), $email, $locale, $now) as $p) {
            $items[] = $p + [
                'editionUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, (string) $p['editionPath']),
                'startsAtText' => $this->formatDate($p['startsAt'] === null ? null : new DateTimeImmutable((string) $p['startsAt']), (string) $p['timezone'], $locale),
                'cancelDeadlineText' => $this->formatDate($p['cancelDeadline'] === null ? null : new DateTimeImmutable((string) $p['cancelDeadline']), (string) $p['timezone'], $locale),
            ];
        }

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addStyleSheet('conferenceEditionPage', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/public.css', ['contexts' => 'frontend']);
        $templateMgr->addHeader('conferenceMyNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'myItems' => $items,
            'myMessage' => $message,
            'myUrl' => $myUrl,
            'pageTitle' => 'plugins.generic.conferenceManager.my.title',
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/my.tpl'));
    }

    private function canManage(PKPRequest $request): bool
    {
        $user = $request->getUser();
        $context = $request->getContext();
        if ($user === null || $context === null) {
            return false;
        }
        return $user->hasRole([\PKP\security\Role::ROLE_ID_MANAGER, \PKP\security\Role::ROLE_ID_SITE_ADMIN], (int) $context->getId())
            || $user->hasRole([\PKP\security\Role::ROLE_ID_SITE_ADMIN], \PKP\core\PKPApplication::SITE_CONTEXT_ID);
    }

    /**
     * Everything the templates show, already localized and formatted.
     *
     * @return array<string, mixed>
     */
    private function viewModel(Edition $edition, string $locale, string $primaryLocale, PKPRequest $request, ?string $contextPath = null): array
    {
        $text = static fn (string $field): string => (string) $edition->text($field)->resolve($locale, $primaryLocale);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dispatcher = $request->getDispatcher();
        $contextPath ??= (string) $request->getContext()?->getPath();

        $dates = [];
        foreach (array_keys(Edition::DATE_FIELDS) as $field) {
            $dates[$field] = $this->formatDate($edition->date($field), $edition->timezone, $locale);
        }

        $proceedingsUrl = null;
        if ($edition->publicationMode === PublicationMode::External) {
            $proceedingsUrl = $edition->externalProceedingsUrl;
        } elseif ($edition->publicationMode === PublicationMode::OjsIssue && $edition->issueId !== null) {
            $publicId = (new IssueGateway())->publishedPublicId($edition->contextId, $edition->issueId);
            if ($publicId !== null) {
                $proceedingsUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, 'issue', 'view', [$publicId]);
            }
        }

        $submissionsOpen = EditionWindows::submissionsOpenAt($edition, $now);
        // Long texts are plain text from the form: escaped, line breaks kept.
        $html = static fn (string $value): string => nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
        return [
            'id' => $edition->id,
            'path' => $edition->path,
            'url' => $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path),
            'name' => $text('name'),
            'description' => $text('description'),
            'venue' => $text('venue'),
            'cfpText' => $text('cfpText'),
            'learningOutcomes' => $text('learningOutcomes'),
            'descriptionHtml' => $html($text('description')),
            'cfpTextHtml' => $html($text('cfpText')),
            'learningOutcomesHtml' => $html($text('learningOutcomes')),
            'status' => $edition->status->value,
            'statusLabel' => __($edition->status->labelKey()),
            'eventTypeLabel' => __($edition->eventType->labelKey()),
            'format' => $edition->format->value,
            'formatLabel' => __($edition->format->labelKey()),
            'timezone' => $edition->timezone,
            'dates' => $dates,
            'hasSubmissions' => $edition->submissionMode !== SubmissionMode::None,
            'hasPapers' => $edition->submissionMode === SubmissionMode::AbstractsAndPapers,
            'submissionsOpen' => $submissionsOpen,
            'submitUrl' => $submissionsOpen ? $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, 'submission') : null,
            'registrationOpen' => EditionWindows::registrationOpenAt($edition, $now),
            'registerUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path, ['register']),
            'proceedingsUrl' => $proceedingsUrl,
            'isbn' => $edition->isbn,
            'hours' => $edition->hours,
            'ects' => $edition->ectsX100 === null ? null : rtrim(rtrim(number_format($edition->ectsX100 / 100, 2, ',', ''), '0'), ','),
            'isDraft' => $edition->status === EditionStatus::Draft,
            'startsAtIso' => $this->isoInZone($edition->date('startsAt'), $edition->timezone),
            'endsAtIso' => $this->isoInZone($edition->date('endsAt'), $edition->timezone),
        ];
    }

    /**
     * schema.org/Event (EDN-07). A physical location is given only for
     * in-person and hybrid events with a venue.
     *
     * @param array<string, mixed> $vm
     * @return array<string, mixed>
     */
    private function jsonLd(Edition $edition, array $vm, PKPRequest $request): array
    {
        $mode = match ($edition->format) {
            EventFormat::InPerson => 'https://schema.org/OfflineEventAttendanceMode',
            EventFormat::Online => 'https://schema.org/OnlineEventAttendanceMode',
            EventFormat::Hybrid => 'https://schema.org/MixedEventAttendanceMode',
        };
        $locations = [];
        if ($edition->format !== EventFormat::Online && $vm['venue'] !== '') {
            $locations[] = ['@type' => 'Place', 'name' => $vm['venue'], 'address' => $vm['venue']];
        }
        if ($edition->format !== EventFormat::InPerson) {
            $locations[] = ['@type' => 'VirtualLocation', 'url' => $vm['url']];
        }
        $context = $request->getContext();
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $vm['name'],
            'url' => $vm['url'],
            'startDate' => $vm['startsAtIso'],
            'endDate' => $vm['endsAtIso'],
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => $mode,
            'organizer' => [
                '@type' => 'Organization',
                'name' => $context ? (string) $context->getLocalizedName() : '',
                'url' => $context ? $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath()) : '',
            ],
        ];
        if ($vm['description'] !== '') {
            $data['description'] = mb_substr(trim(strip_tags($vm['description'])), 0, 5000);
        }
        if ($locations !== []) {
            $data['location'] = count($locations) === 1 ? $locations[0] : $locations;
        }
        return array_filter($data, static fn ($v) => $v !== null && $v !== '');
    }

    private function isoInZone(?DateTimeImmutable $date, string $timezone): ?string
    {
        return $date?->setTimezone(new DateTimeZone($timezone))->format(DATE_ATOM);
    }

    private function formatDate(?DateTimeImmutable $date, string $timezone, string $locale): ?string
    {
        if ($date === null) {
            return null;
        }
        $local = $date->setTimezone(new DateTimeZone($timezone));
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT, $timezone);
            $formatted = $formatter->format($local);
            if (is_string($formatted)) {
                return $formatted;
            }
        }
        return $local->format('Y-m-d H:i');
    }
}
