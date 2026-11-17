<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Pages;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Application\ProgramService;
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
        if (($args[0] ?? null) === 'bids' && !isset($args[1])) {
            return $this->bids($edition, $info, $request);
        }
        if (($args[0] ?? null) === 'program') {
            $format = $args[1] ?? null;
            if ($format === null && !isset($args[2])) {
                return $this->program($edition, $info, $request);
            }
            if ($format === 'ics' && ($args[2] ?? null) === 'mine' && !isset($args[3])) {
                return $this->programIcs($edition, $info, $request, null, true);
            }
            if ($format === 'ics' && (!isset($args[2]) || ctype_digit((string) $args[2])) && !isset($args[3])) {
                return $this->programIcs($edition, $info, $request, isset($args[2]) ? (int) $args[2] : null);
            }
            if ($format === 'pdf' && !isset($args[2])) {
                return $this->program($edition, $info, $request, true);
            }
        }
        if (isset($args[0])) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $locale = Locale::getLocale();
        $vm = $this->viewModel($edition, $locale, $info->primaryLocale, $request);
        $partners = ServiceFactory::partners()->publicGroups($info, (int) $edition->id, $locale, ServiceFactory::partnerLogoUrl());
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->addHeader('conferenceEditionJsonLd', '<script type="application/ld+json">'
            . json_encode($this->jsonLd($edition, $vm, $request, $partners), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
            . '</script>', ['contexts' => 'frontend']);
        if ($edition->status === EditionStatus::Draft) {
            $templateMgr->addHeader('conferenceEditionNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        }
        $templateMgr->assign([
            'edition' => $vm,
            'partnerGroups' => $partners,
            'pageTitleTranslated' => $vm['name'],
            // A registered person sees the way to the cabinet instead of the registration button.
            'myStatus' => $this->myStatusIn($info, (string) $edition->path, $request, $locale),
            'myUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $request->getContext()?->getPath(), self::PAGE, 'my'),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/edition.tpl'));
    }

    /**
     * PUB-05: the events of the journal, at /{journal}/conference: the
     * coming and current ones first, then the past ones. Drafts are not
     * listed.
     *
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function index($args, $request)
    {
        $context = $request->getContext();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        [$coming, $past] = $this->journalEditions(ContextGateway::fromContext($context), $request);
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->assign([
            'pageTitle' => 'plugins.generic.conferenceManager.editionsPage.title',
            'comingEditions' => $coming,
            'pastEditions' => $past,
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/editions.tpl'));
    }

    /**
     * PUB-05: the coming events on the home page of a journal in the
     * conference site mode; empty when there are none.
     */
    public function homeBlock(PKPRequest $request): string
    {
        $context = $request->getContext();
        if ($context === null) {
            return '';
        }
        [$coming] = $this->journalEditions(ContextGateway::fromContext($context), $request);
        if ($coming === []) {
            return '';
        }
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->assign([
            'homeEditions' => array_slice($coming, 0, 5),
            'homeEditionsUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE),
        ]);
        return (string) $templateMgr->fetch($this->plugin->getTemplateResource('frontend/homeEditions.tpl'));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} coming (by start), past (latest first)
     */
    private function journalEditions(ContextInfo $info, PKPRequest $request): array
    {
        $statuses = array_values(array_filter(EditionStatus::cases(), static fn (EditionStatus $s): bool => $s !== EditionStatus::Draft));
        $locale = Locale::getLocale();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $coming = $past = [];
        foreach (ServiceFactory::editions()->list($info, $statuses, 100)['items'] as $edition) {
            $end = $edition->date('endsAt') ?? $edition->date('startsAt');
            $isPast = $edition->status === EditionStatus::Archived || ($end !== null && $end < $now);
            $vm = $this->viewModel($edition, $locale, $info->primaryLocale, $request);
            $vm['sortKey'] = $edition->date('startsAt')?->getTimestamp() ?? 0;
            if ($isPast) {
                $past[] = $vm;
            } else {
                $coming[] = $vm;
            }
        }
        usort($coming, static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey']);
        usort($past, static fn (array $a, array $b): int => $b['sortKey'] <=> $a['sortKey']);
        return [$coming, $past];
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
        $this->plugin->addPublicStyles($templateMgr, $request);
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
        $this->plugin->addPublicStyles($templateMgr, $request);
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
            'minor' => (bool) $request->getUserVar('minor'),
            'guardianConsent' => (bool) $request->getUserVar('guardianConsent'),
            'promoCode' => mb_substr(trim((string) $request->getUserVar('promoCode')), 0, 32),
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
                    $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my', null, array_filter([
                        'registered' => $result['participationId'],
                        'waitlisted' => $result['status'] === \APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus::Waitlisted->value ? 1 : null,
                        'promoLost' => empty($result['promoLost']) ? null : 1,
                    ])));
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

        // REG-11, REG-12: the code field only where codes exist; a note when the seats are taken.
        $hasPromo = (ServiceFactory::promoCodes()->ofEdition($info, (int) $edition->id) ?? []) !== [];
        $seats = $service->seats($info, (int) $edition->id, $locale);
        $full = $seats !== null && $seats['seats'] !== null && $seats['taken'] >= $seats['seats']
            && in_array(\APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm::InPerson, ParticipantService::listenerForms($edition), true);
        $queue = $seats === null || !$full ? 0 : count($seats['queue']);

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->addHeader('conferenceRegisterNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $vm = $this->viewModel($edition, $locale, $info->primaryLocale, $request);
        $templateMgr->assign([
            'edition' => $vm,
            'registerOpen' => EditionWindows::registrationOpenAt($edition, $now),
            'registerUrl' => $registerUrl,
            'myStatus' => $this->myStatusIn($info, (string) $edition->path, $request, $locale),
            'myUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my'),
            'registerErrors' => $errors,
            'registerValues' => $values,
            'registerForms' => $forms,
            'registerCategories' => $categories,
            'registerHasPromo' => $hasPromo,
            'registerSeatsFull' => $full,
            'registerQueue' => $queue,
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
            $message = ['type' => 'status', 'text' => __($request->getUserVar('waitlisted') ? 'plugins.generic.conferenceManager.waitlist.done' : 'plugins.generic.conferenceManager.listener.done')
                . ($request->getUserVar('promoLost') ? ' ' . __('plugins.generic.conferenceManager.promo.error.usedUp') : '')];
        }
        if ($request->isPost()) {
            if (!$request->checkCSRF()) {
                $message = ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.confirm.retry')];
            } elseif ($request->getUserVar('payerInvoiceId')) {
                // JUR-05: who pays the invoice.
                $errors = ServiceFactory::invoices()->savePayerDetails($info, (int) $request->getUserVar('payerInvoiceId'), (int) $user->getId(), $email, [
                    'kind' => (string) $request->getUserVar('payerKind'),
                    'organization' => (string) $request->getUserVar('payerOrganization'),
                    'legalId' => (string) $request->getUserVar('payerLegalId'),
                    'taxId' => (string) $request->getUserVar('payerTaxId'),
                ], $now);
                if ($errors !== null && $errors->isEmpty()) {
                    $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my', null, ['payer' => 1]));
                }
                $message = ['type' => 'alert', 'text' => $errors === null ? __('plugins.generic.conferenceManager.payer.error.locked')
                    : implode(' ', array_map(static fn (string $k): string => __($k), array_merge(...array_values($errors->toArray()))))];
            } elseif ($request->getUserVar('promoParticipationId')) {
                // REG-11: a promo code after the registration.
                [$applied, $promoErrors] = $service->applyPromo($info, (int) $request->getUserVar('promoParticipationId'), (int) $user->getId(), $email, (string) $request->getUserVar('promoCode'), $now);
                if ($applied) {
                    $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my', null, ['promo' => 1]));
                }
                $message = ['type' => 'alert', 'text' => implode(' ', array_map(static fn (string $k): string => __($k), array_merge(...array_values($promoErrors->toArray()))))];
            } elseif ($request->getUserVar('invoiceId')) {
                $message = $this->uploadProof($info, (int) $request->getUserVar('invoiceId'), (int) $user->getId(), $email, $now, $locale);
                if ($message['type'] === 'status') {
                    $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my', null, ['proof' => $message['duplicate'] ? 'duplicate' : 'sent']));
                }
            } else {
                $out = $service->cancel($info, (int) $request->getUserVar('participationId'), (int) $user->getId(), $email, false, $now, $request->getRemoteAddr());
                if ($out['result'] === ParticipantService::CANCELLED) {
                    $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my', null, ['cancelled' => 1]));
                }
                $message = ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.participation.error.' . $out['result'])];
            }
        } elseif ($request->getUserVar('payer')) {
            $message = ['type' => 'status', 'text' => __('plugins.generic.conferenceManager.payer.saved')];
        } elseif ($request->getUserVar('promo')) {
            $message = ['type' => 'status', 'text' => __('plugins.generic.conferenceManager.promo.applied')];
        } elseif (in_array($request->getUserVar('card'), ['paid', 'pending', 'error', 'limit'], true)) {
            // PAY-09: back from the payment page of the gateway.
            $message = ['type' => in_array($request->getUserVar('card'), ['error', 'limit'], true) ? 'alert' : 'status', 'text' => __('plugins.generic.conferenceManager.gateway.my.' . $request->getUserVar('card'))];
        } elseif ($request->getUserVar('cancelled')) {
            $message = ['type' => 'status', 'text' => __('plugins.generic.conferenceManager.participation.cancelled')];
        } elseif ($request->getUserVar('proof')) {
            $message = ['type' => 'status', 'text' => __('plugins.generic.conferenceManager.proof.sent')
                . ($request->getUserVar('proof') === 'duplicate' ? ' ' . __('plugins.generic.conferenceManager.proof.duplicateWarning') : '')];
        }

        $items = [];
        $letters = ServiceFactory::invitationLetters();
        foreach ($service->myParticipations($info, (int) $user->getId(), $email, $locale, $now) as $p) {
            $letterUrl = $letters->isAvailable($info, $p) ? $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'invitationLetter', [(string) $p['participationId']]) : null;
            $ended = $p['endsAt'] !== null && new DateTimeImmutable((string) $p['endsAt']) < $now->sub(new \DateInterval('P1D'));
            $hasTicket = $p['status'] === 'active' && $p['participationForm'] !== 'online' && $p['editionFormat'] !== 'online' && !$ended;
            $items[] = $p + [
                'ticket' => $hasTicket ? $this->ticketImage((int) $p['participationId']) : null,
                'ticketUrl' => $hasTicket ? $this->ticketUrl((int) $p['participationId']) : null,
                'letterUrl' => $letterUrl,
                'letterUrlEn' => $letterUrl === null ? null : $letterUrl . (str_contains($letterUrl, '?') ? '&' : '?') . 'lang=en',
                'editionUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, (string) $p['editionPath']),
                'startsAtText' => $this->formatDate($p['startsAt'] === null ? null : new DateTimeImmutable((string) $p['startsAt']), (string) $p['timezone'], $locale),
                'cancelDeadlineText' => $this->formatDate($p['cancelDeadline'] === null ? null : new DateTimeImmutable((string) $p['cancelDeadline']), (string) $p['timezone'], $locale),
            ];
        }

        $invoices = [];
        $proofs = ServiceFactory::proofs();
        $online = ServiceFactory::onlinePayments();
        $cardOn = $online->isOn($info);
        $cardSandbox = $cardOn && $online->isSandbox($info);
        foreach ($proofs->withProofs($info, ServiceFactory::invoices()->mine($info, (int) $user->getId(), $email, $locale), $locale) as $i) {
            $i['proofs'] = array_map(fn (array $p): array => $p + [
                'url' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'receipt', [(string) $p['id']]),
                'uploadedAtText' => $this->formatDate($p['uploadedAt'] === null ? null : new DateTimeImmutable((string) $p['uploadedAt']), (string) $i['timezone'], $locale),
            ], $i['proofs']);
            $invoices[] = $i + [
                'pdfUrl' => $i['hasDocument'] ? $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'invoice', [(string) $i['id']]) : null,
                'issuedAtText' => $this->formatDate($i['issuedAt'] === null ? null : new DateTimeImmutable((string) $i['issuedAt']), (string) $i['timezone'], $locale),
                'dueAtText' => $this->formatDate($i['dueAt'] === null ? null : new DateTimeImmutable((string) $i['dueAt']), (string) $i['timezone'], $locale),
                'instructionsHtml' => nl2br(htmlspecialchars((string) $i['instructions'])),
                'canEditPayer' => $i['isOpen'] && $i['hasDocument'] && (int) ($i['paid']['minor'] ?? 0) === 0,
                'cardPayUrl' => $cardOn && $i['mode'] === 'acquiring' && $i['isOpen'] && $online->canPayInvoice($info, (int) $i['id'])
                    ? $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'payment', [(string) $i['id']]) : null,
                'cardSandbox' => $cardSandbox,
            ];
        }
        $payerFields = ServiceFactory::invoices()->payerFields($info);

        $certificates = [];
        $participantIds = array_values(array_unique(array_map(static fn (array $p): int => (int) $p['participantId'], $items)));
        foreach (ServiceFactory::certificates()->mine($info, $participantIds, $locale) as $c) {
            $pdfUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'certificate', [(string) $c['id']]);
            $alt = [];
            foreach (array_slice($c['languages'], 1) as $l) {
                $alt[] = ['language' => strtoupper($l), 'url' => $pdfUrl . (str_contains($pdfUrl, '?') ? '&' : '?') . 'lang=' . rawurlencode($l)];
            }
            $certificates[] = $c + [
                'pdfUrl' => $pdfUrl,
                'altPdf' => $alt,
                'issuedAtText' => $this->formatDate($c['issuedAt'] === null ? null : new DateTimeImmutable((string) $c['issuedAt']), $info->serverTimezone, $locale),
            ];
        }

        // ATT-01: sessions the person chairs, to mark who was present.
        $chaired = [];
        foreach (ServiceFactory::attendance()->chairedSessions($info, $participantIds, $locale) as $s) {
            $chaired[] = $s + [
                'url' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'sessionAttendance', [(string) $s['editionId'], (string) $s['sessionId']]),
                'startsAtText' => $this->formatDate($s['startsAt'], $s['timezone'], $locale),
                'typeLabel' => __('plugins.generic.conferenceManager.program.session.' . $s['type']),
            ];
        }

        // PRG-09: the person's own program, sessions that have not ended.
        $myProgram = [];
        $editionIds = array_values(array_unique(array_map(static fn (array $p): int => (int) $p['editionId'], array_filter($items, static fn (array $p): bool => $p['status'] === 'active'))));
        foreach (ServiceFactory::personalProgram()->upcoming($info, (int) $user->getId(), $participantIds, $editionIds, $locale, $now) as $e) {
            $programUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, $e['editionPath'], ['program']);
            $e['programUrl'] = $programUrl;
            $e['icsUrl'] = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, $e['editionPath'], ['program', 'ics', 'mine']);
            foreach ($e['sessions'] as &$s) {
                $s['url'] = $programUrl . '#cm-session-' . $s['id'];
                $s['whenText'] = $this->formatDate($s['startsAt'], $e['timezone'], $locale) . ' – ' . $s['endsAt']->setTimezone(new DateTimeZone($e['timezone']))->format('H:i');
                $s['reasonText'] = implode(', ', array_map(static fn (string $r): string => __('plugins.generic.conferenceManager.myProgram.reason.' . $r), $s['reasons']));
                $s['typeLabel'] = __('plugins.generic.conferenceManager.program.session.' . $s['type']);
            }
            unset($s);
            $myProgram[] = $e;
        }

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->addHeader('conferenceMyNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'myItems' => $items,
            // Replaced and cancelled invoices go last, folded away: they are history, not something to pay.
            'myInvoices' => array_merge(
                array_values(array_filter($invoices, static fn (array $i): bool => !in_array($i['status'], ['superseded', 'cancelled'], true))),
                array_map(static fn (array $i): array => $i + ['isOld' => true], array_values(array_filter($invoices, static fn (array $i): bool => in_array($i['status'], ['superseded', 'cancelled'], true)))),
            ),
            'oldInvoiceCount' => count(array_filter($invoices, static fn (array $i): bool => in_array($i['status'], ['superseded', 'cancelled'], true))),
            'payerFields' => $payerFields,
            'myDataUrl' => $dispatcher->url($request, Application::ROUTE_API, $context->getPath(), 'conference/me/personal-data'),
            'myCertificates' => $certificates,
            'mySessions' => $chaired,
            'myProgram' => $myProgram,
            'myBidding' => array_map(static fn (array $b): array => [
                'until' => $b['until'] === '' ? '' : (string) (new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'UTC'))->format(new DateTimeImmutable($b['until'], new DateTimeZone('UTC'))),
                'url' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, $b['path'], ['bids']),
            ] + $b, ServiceFactory::reviewBidding()->openFor($info, (int) $user->getId(), $locale, $now)),
            'myMessage' => $message,
            'myUrl' => $myUrl,
            'pageTitle' => 'plugins.generic.conferenceManager.my.title',
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/my.tpl'));
    }

    /**
     * DEC-10: a reviewer of the journal marks which submissions of the
     * edition they would like to review. Others get "not found".
     */
    private function bids(Edition $edition, \APP\plugins\generic\conferenceManager\classes\Application\ContextInfo $info, $request): mixed
    {
        $context = $request->getContext();
        $dispatcher = $request->getDispatcher();
        $url = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, $edition->path, ['bids']);
        $user = $request->getUser();
        if ($user === null) {
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'login', null, null, ['source' => $url]));
        }
        $locale = Locale::getLocale();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $service = ServiceFactory::reviewBidding();
        $userId = (int) $user->getId();
        $page = $service->reviewerPage($info, $edition, $userId, $locale, $now);
        if ($page === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $message = null;
        if ($request->isPost()) {
            if (!$request->checkCSRF()) {
                $message = ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.confirm.retry')];
            } else {
                [$saved, $errors] = $service->saveBids($info, $edition, $userId, (array) $request->getUserVar('bid'), $locale, $now) ?? [0, null];
                if ($errors !== null && !$errors->isEmpty()) {
                    $message = ['type' => 'alert', 'text' => implode(' ', array_unique(array_map(static fn (string $k): string => __($k), array_merge(...array_values($errors->toArray())))))];
                } else {
                    $request->redirectUrl($url . '?saved=' . (int) $saved);
                }
            }
        } elseif ($request->getUserVar('saved') !== null) {
            $message = ['type' => 'status', 'text' => __('plugins.generic.conferenceManager.bids.saved', ['n' => (int) $request->getUserVar('saved')])];
        }
        $choices = array_map(static fn (\APP\plugins\generic\conferenceManager\classes\Domain\Review\Bid $b): array => ['value' => $b->value, 'label' => __($b->labelKey())], \APP\plugins\generic\conferenceManager\classes\Domain\Review\Bid::cases());

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->addHeader('conferenceBidsNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'bidsEditionName' => (string) $edition->text('name')->resolve($locale, $info->primaryLocale),
            'bidsEditionUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, $edition->path),
            'bidsPage' => $page,
            'bidsUntil' => (new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'UTC'))->format(new DateTimeImmutable($page['until'], new DateTimeZone('UTC'))),
            'bidsChoices' => $choices,
            'bidsUrl' => $url,
            'bidsMessage' => $message,
            'pageTitle' => 'plugins.generic.conferenceManager.bids.title',
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/bids.tpl'));
    }

    /**
     * Saves the receipt sent from the cabinet form (FEE-09).
     *
     * @return array{type: string, text: string, duplicate: bool}
     */
    private function uploadProof(\APP\plugins\generic\conferenceManager\classes\Application\ContextInfo $info, int $invoiceId, int $userId, string $email, DateTimeImmutable $now, string $locale): array
    {
        $file = $_FILES['proof'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $tmp = is_array($file) ? (string) ($file['tmp_name'] ?? '') : '';
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.proof.error.tooLarge'), 'duplicate' => false];
        }
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            return ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.proof.error.empty'), 'duplicate' => false];
        }
        $out = ServiceFactory::proofs()->upload($info, $invoiceId, $userId, $email, $tmp, (string) ($file['name'] ?? ''), $now, $locale);
        if ($out === null) {
            return ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.proof.error.notYours'), 'duplicate' => false];
        }
        [$proof, $errors, $warnings] = $out;
        if ($proof === null) {
            $keys = array_merge(...array_values($errors->toArray()));
            return ['type' => 'alert', 'text' => implode(' ', array_map(static fn (string $k): string => __($k), $keys)), 'duplicate' => false];
        }
        return ['type' => 'status', 'text' => '', 'duplicate' => in_array('duplicate', $warnings, true)];
    }

    /**
     * A receipt file (FEE-09) for its payer or a registration manager. The
     * file itself lives outside the web root.
     */
    public function receipt($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if ($context === null || $user === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $info = ContextGateway::fromContext($context);
        $manager = (new \APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway($this->plugin))->canManageRegistrations($info->id, (int) $user->getId());
        $file = ServiceFactory::proofs()->file($info, (int) ($args[0] ?? 0), $manager ? null : [(int) $user->getId(), (string) $user->getEmail()]);
        if ($file === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['name']);
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
        header('Content-Length: ' . strlen($file['content']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        echo $file['content'];
        exit;
    }

    /**
     * PAY-09: card payment of an invoice.
     *   POST payment/{invoiceId}          the payer starts an attempt: a form to the gateway's page
     *   GET|POST payment/{invoiceId}/return  back from the gateway: to the cabinet with the result
     *   POST payment/callback/{gateway}   the gateway's signed report, server to server
     *
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function payment($args, $request)
    {
        $context = $request->getContext();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $info = ContextGateway::fromContext($context);
        $dispatcher = $request->getDispatcher();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $online = ServiceFactory::onlinePayments();
        $my = static fn (string $result): string => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my', null, ['card' => $result]);

        if (($args[0] ?? null) === 'callback' && isset($args[1]) && !isset($args[2])) {
            $ok = $request->isPost() && $online->callback($info, (string) $args[1], $_POST, (string) file_get_contents('php://input'), $now);
            http_response_code($ok ? 200 : 400);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo $ok ? 'OK' : 'Bad request';
            exit;
        }
        if (!ctype_digit((string) ($args[0] ?? ''))) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $invoiceId = (int) $args[0];
        if (($args[1] ?? null) === 'return' && !isset($args[2])) {
            $online->refreshInvoice($info, $invoiceId, $now);
            $invoice = ServiceFactory::invoices()->find($info, $invoiceId);
            $paid = $invoice !== null && $invoice->status === \APP\plugins\generic\conferenceManager\classes\Domain\Payment\InvoiceStatus::Paid;
            $request->redirectUrl($my($paid ? 'paid' : 'pending'));
        }
        $user = $request->getUser();
        if (isset($args[1]) || !$request->isPost() || $user === null || !$request->checkCSRF()) {
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my'));
        }
        $invoice = ServiceFactory::invoices()->find($info, $invoiceId);
        $locale = Locale::getLocale();
        $description = __('plugins.generic.conferenceManager.gateway.description', ['number' => (string) ($invoice->number ?? $invoiceId)]);
        $page = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'payment', [(string) $invoiceId, 'return']);
        $callback = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'payment', ['callback', '__gateway__']);
        $gatewayId = (string) ($online->settings($info)['gateway'] ?? '');
        [$form, $error] = $online->start(
            $info,
            $invoiceId,
            (int) $user->getId(),
            (string) $user->getEmail(),
            $description,
            static fn (string $orderId): string => $page,
            str_replace('__gateway__', rawurlencode($gatewayId), $callback),
            str_starts_with($locale, 'en') ? 'en' : 'uk',
            $now,
        );
        if ($form === null) {
            $request->redirectUrl($my(str_ends_with((string) $error, 'tooManyAttempts') ? 'limit' : 'error'));
        }
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->addHeader('conferencePayNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'payForm' => $form,
            'payDescription' => $description,
            'payAmount' => $invoice === null ? '' : $invoice->due()->decimal() . ' ' . $invoice->due()->currency,
            'payGateway' => (string) (ServiceFactory::gateways()->get($gatewayId)?->name() ?? ''),
            'payBackUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my'),
            'pageTitle' => 'plugins.generic.conferenceManager.gateway.pay.title',
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/pay.tpl'));
    }

    /**
     * The invoice PDF (FEE-06) for the payer or a registration manager.
     */
    public function invoice($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        if ($user === null) {
            $dispatcher = $request->getDispatcher();
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'login', null, null, [
                'source' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'invoice', [(string) ($args[0] ?? '')]),
            ]));
        }
        $info = ContextGateway::fromContext($context);
        $service = ServiceFactory::invoices();
        $invoice = $service->find($info, (int) ($args[0] ?? 0));
        $allowed = $invoice !== null && (
            $service->isPayer($invoice, (int) $user->getId(), (string) $user->getEmail())
            || (new \APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway($this->plugin))->canManageRegistrations($info->id, (int) $user->getId())
        );
        $pdf = $allowed ? ServiceFactory::invoiceDocuments()->pdf($info, $invoice) : null;
        if ($pdf === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $pdf['name'] . '"');
        header('Content-Length: ' . strlen($pdf['content']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $pdf['content'];
        exit;
    }

    /**
     * CRT-04: the certificate PDF for its holder or a registration manager.
     */
    public function certificate($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        if ($user === null) {
            $dispatcher = $request->getDispatcher();
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'login', null, null, [
                'source' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'certificate', [(string) ($args[0] ?? '')]),
            ]));
        }
        $info = ContextGateway::fromContext($context);
        $manager = (new \APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway($this->plugin))->canManageRegistrations($info->id, (int) $user->getId());
        $owner = $manager ? null : $this->ownParticipantIds($info, (int) $user->getId(), (string) $user->getEmail());
        $lang = $request->getUserVar('lang');
        $pdf = ServiceFactory::certificates()->pdf($info, (int) ($args[0] ?? 0), $owner, is_string($lang) && preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : null);
        if ($pdf === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $pdf['name'] . '"');
        header('Content-Length: ' . strlen($pdf['content']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $pdf['content'];
        exit;
    }

    /**
     * COM-04: the letter of invitation for the participant or a
     * registration manager.
     */
    public function invitationLetter($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        if ($user === null) {
            $dispatcher = $request->getDispatcher();
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'login', null, null, [
                'source' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'invitationLetter', [(string) ($args[0] ?? '')]),
            ]));
        }
        $info = ContextGateway::fromContext($context);
        $manager = (new \APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway($this->plugin))->canManageRegistrations($info->id, (int) $user->getId());
        $owner = $manager ? null : $this->ownParticipantIds($info, (int) $user->getId(), (string) $user->getEmail());
        $lang = $request->getUserVar('lang');
        $pdf = ServiceFactory::invitationLetters()->pdf($info, (int) ($args[0] ?? 0), $owner, is_string($lang) ? $lang : substr(Locale::getLocale(), 0, 2), new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if ($pdf === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $pdf['name'] . '"');
        header('Content-Length: ' . strlen($pdf['content']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $pdf['content'];
        exit;
    }

    /**
     * ATT-01, ATT-02 per session: the chair (moderator) of a session or a
     * registration manager marks who was present, or loads the meeting
     * report of the session. URL: conference/sessionAttendance/{editionId}/{sessionId}.
     *
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function sessionAttendance($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $editionId = (int) ($args[0] ?? 0);
        $sessionId = (int) ($args[1] ?? 0);
        $dispatcher = $request->getDispatcher();
        $pageUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'sessionAttendance', [(string) $editionId, (string) $sessionId]);
        if ($user === null) {
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'login', null, null, ['source' => $pageUrl]));
        }
        $info = ContextGateway::fromContext($context);
        $locale = Locale::getLocale();
        $service = ServiceFactory::attendance();
        $manager = (new \APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway($this->plugin))->canManageRegistrations($info->id, (int) $user->getId());
        if (!$manager && !$service->chairs($sessionId, $this->ownParticipantIds($info, (int) $user->getId(), (string) $user->getEmail()))) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $sheet = $service->sessionSheet($info, $editionId, $sessionId, $locale);
        $edition = ServiceFactory::editions()->get($info, $editionId);
        if ($sheet === null || $edition === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $actor = (int) $user->getId();
        $message = null;
        $import = null;
        $reportData = '';
        $threshold = (int) $sheet['threshold'];
        $k = 'plugins.generic.conferenceManager.sessionAttendance.';
        if ($request->isPost()) {
            if (!$request->checkCSRF()) {
                $message = ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.confirm.retry')];
            } elseif ($request->getUserVar('importReport') !== null) {
                $threshold = (int) $request->getUserVar('threshold');
                $content = '';
                $file = $_FILES['report'] ?? null;
                if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name'])) {
                    $content = (string) file_get_contents((string) $file['tmp_name']);
                } elseif (is_string($request->getUserVar('reportData'))) {
                    $content = (string) base64_decode((string) $request->getUserVar('reportData'), true);
                }
                $apply = $request->getUserVar('apply') !== null;
                [$result, $errors] = $service->importSession($info, $editionId, $sessionId, $content, $request->getUserVar('threshold'), $apply, $locale, $actor, $now) ?? [null, null];
                if ($result === null) {
                    $message = ['type' => 'alert', 'text' => $errors === null ? '' : implode(' ', array_map(static fn (string $key): string => __($key), array_merge(...array_values($errors->toArray()))))];
                } else {
                    $import = $result;
                    $reportData = $apply ? '' : base64_encode($content);
                    $message = ['type' => 'status', 'text' => __($k . ($apply ? 'imported' : 'previewed'), [
                        'rows' => $result['rows'], 'matched' => count($result['matched']), 'present' => $result['present'], 'unmatched' => count($result['unmatched']),
                    ])];
                    if ($apply) {
                        $sheet = $service->sessionSheet($info, $editionId, $sessionId, $locale) ?? $sheet;
                    }
                }
            } else {
                // Only what changed on the form, so that marks from a report keep their minutes.
                $shown = array_flip(array_map('intval', (array) $request->getUserVar('shown')));
                $checked = array_flip(array_map('intval', (array) $request->getUserVar('present')));
                $add = $remove = [];
                $changedPeople = 0;
                foreach ($sheet['people'] as $p) {
                    $id = $p['participationId'];
                    if (!isset($shown[$id])) {
                        continue;
                    }
                    // A row is one person; the mark goes to all their participations.
                    if (isset($checked[$id]) && !$p['present']) {
                        array_push($add, ...$p['participationIds']);
                        $changedPeople++;
                    } elseif (!isset($checked[$id]) && $p['present']) {
                        array_push($remove, ...$p['participationIds']);
                        $changedPeople++;
                    }
                }
                $changed = (int) $service->markSession($info, $editionId, $sessionId, $add, true, $actor, $now)
                    + (int) $service->markSession($info, $editionId, $sessionId, $remove, false, $actor, $now);
                $request->redirectUrl($pageUrl . (str_contains($pageUrl, '?') ? '&' : '?') . 'saved=' . ($changed > 0 ? $changedPeople : 0));
            }
        } elseif ($request->getUserVar('saved') !== null) {
            $message = ['type' => 'status', 'text' => __($k . 'saved', ['n' => (int) $request->getUserVar('saved')])];
        }

        $tz = $edition->timezone;
        $session = $sheet['session'];
        $typeLabel = __('plugins.generic.conferenceManager.program.session.' . $session['type']);
        $title = $session['title'] !== '' ? $session['title'] : $typeLabel;
        $roles = [];
        foreach ($sheet['people'] as $i => $p) {
            $role = \APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole::tryFrom($p['role']);
            $roles[$i] = $role === null ? $p['role'] : __($role->labelKey());
        }
        $people = [];
        foreach ($sheet['people'] as $i => $p) {
            $people[] = $p + ['roleLabel' => $roles[$i]];
        }

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->addJavaScript('conferenceSessionAttendance', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/js/sessionAttendance.js', ['contexts' => 'frontend']);
        $templateMgr->addHeader('conferenceSessionNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'saPageUrl' => $pageUrl,
            'saEditionName' => (string) $edition->text('name')->resolve($locale, $info->primaryLocale),
            'saEditionUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, $edition->path),
            'saTitle' => $title,
            'saTypeLabel' => $typeLabel,
            'saWhen' => $this->formatDate($session['startsAt'], $tz, $locale) . ' – ' . $session['endsAt']->setTimezone(new DateTimeZone($tz))->format('H:i'),
            'saHours' => $session['hours'],
            'saMinutes' => $session['minutes'],
            'saPresent' => $sheet['present'],
            'saPeople' => $people,
            'saTotal' => count($people),
            'saUnmatched' => $import === null ? 0 : count($import['unmatched']),
            'saThreshold' => $threshold,
            'saImport' => $import,
            'saReportData' => $reportData,
            'saMessage' => $message,
            'saManager' => $manager,
            'saBackUrl' => $manager
                ? $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), ManageConferenceHandler::PAGE)
                : $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'my'),
            'pageTitleTranslated' => __($k . 'title') . ': ' . $title,
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/sessionAttendance.tpl'));
    }

    /**
     * ATT-04: the page a QR entry ticket leads to, conference/checkin/{code}.
     * A registration manager marks the person present at the event and,
     * if chosen, at a session; a chair only at the sessions they chair.
     * The owner sees their own ticket; anyone else gets 404.
     *
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function checkin($args, $request)
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $token = (string) ($args[0] ?? '');
        $dispatcher = $request->getDispatcher();
        $pageUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'checkin', [$token]);
        if ($user === null) {
            $request->redirectUrl($dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'login', null, null, ['source' => $pageUrl]));
        }
        $info = ContextGateway::fromContext($context);
        $locale = Locale::getLocale();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $service = ServiceFactory::attendance();
        $pid = \APP\plugins\generic\conferenceManager\classes\Domain\Attendance\CheckinToken::read($token, ServiceFactory::checkinSecret());
        $card = $pid === null ? null : $service->checkinCard($info, $pid, $locale, $now);
        if ($card === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $manager = (new \APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway($this->plugin))->canManageRegistrations($info->id, (int) $user->getId());
        $mine = $this->ownParticipantIds($info, (int) $user->getId(), (string) $user->getEmail());
        $chaired = array_values(array_filter($card['sessions'], static fn (array $s): bool => array_intersect($s['chairs'], $mine) !== []));
        $owner = in_array($card['participantId'], $mine, true);
        if (!$manager && $chaired === [] && !$owner) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $staff = $manager || $chaired !== [];
        $choices = $manager ? $card['sessions'] : $chaired;
        $k = 'plugins.generic.conferenceManager.checkin.';
        // The session chosen last stays chosen for the next ticket.
        $cookie = 'cmCheckinSession' . $card['editionId'];
        $remembered = (int) ($_COOKIE[$cookie] ?? 0);
        $ids = array_column($choices, 'id');
        $chosen = in_array($remembered, $ids, true) ? $remembered : (in_array($card['currentSessionId'], $ids, true) ? $card['currentSessionId'] : null);
        $message = null;
        if ($staff && $request->isPost()) {
            if (!$request->checkCSRF()) {
                $message = ['type' => 'alert', 'text' => __('plugins.generic.conferenceManager.confirm.retry')];
            } else {
                $sessionId = (int) $request->getUserVar('sessionId');
                $sessionId = in_array($sessionId, $ids, true) ? $sessionId : null;
                setcookie($cookie, (string) ($sessionId ?? 0), ['expires' => time() + 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $request->getProtocol() === 'https']);
                $done = $card['active'] ? $service->checkIn($info, $card['participationId'], $manager, $sessionId, (int) $user->getId(), $now) : null;
                $request->redirectUrl($pageUrl . (str_contains($pageUrl, '?') ? '&' : '?') . 'done=' . ($done === null ? 'no' : (($done['edition'] || $done['session']) ? 'yes' : 'already')));
            }
        } elseif ($staff && is_string($request->getUserVar('done'))) {
            $done = $request->getUserVar('done');
            $message = ['type' => $done === 'no' ? 'alert' : 'status', 'text' => __($k . 'done.' . ($done === 'yes' ? 'yes' : ($done === 'already' ? 'already' : 'no')))];
        }
        $tz = $card['timezone'];
        $sessions = [];
        foreach ($choices as $s) {
            $sessions[] = [
                'id' => $s['id'],
                'label' => $s['startsAt']->setTimezone(new DateTimeZone($tz))->format('d.m H:i') . '–' . $s['endsAt']->setTimezone(new DateTimeZone($tz))->format('H:i')
                    . ' · ' . ($s['title'] !== '' ? $s['title'] : __('plugins.generic.conferenceManager.program.session.' . $s['type'])),
                'present' => $s['present'],
            ];
        }
        $role = \APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole::tryFrom($card['role']);
        $form = \APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationForm::tryFrom($card['form']);

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->addHeader('conferenceCheckinNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'ciUrl' => $pageUrl,
            'ciCard' => $card,
            'ciStaff' => $staff,
            'ciManager' => $manager,
            'ciSessions' => $sessions,
            'ciChosen' => $chosen,
            'ciRole' => $role === null ? $card['role'] : __($role->labelKey()),
            'ciForm' => $form === null ? $card['form'] : __($form->labelKey()),
            'ciMessage' => $message,
            'ciTicket' => !$staff ? $this->ticketImage($card['participationId']) : null,
            'pageTitleTranslated' => __($k . 'title') . ': ' . $card['name'],
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/checkin.tpl'));
    }

    /** ATT-04: the link of an entry ticket (conference/checkin/{code}), null without a secret. */
    private function ticketUrl(int $participationId): ?string
    {
        $request = \APP\core\Application::get()->getRequest();
        $context = $request->getContext();
        $secret = ServiceFactory::checkinSecret();
        if ($context === null || $secret === '') {
            return null;
        }
        return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'checkin', [
            \APP\plugins\generic\conferenceManager\classes\Domain\Attendance\CheckinToken::make($participationId, $secret),
        ]);
    }

    /** ATT-04: the QR code of an entry ticket as an <img>. */
    private function ticketImage(int $participationId): ?string
    {
        $url = $this->ticketUrl($participationId);
        return $url === null ? null : \APP\plugins\generic\conferenceManager\classes\Domain\Certificate\QrCode::encode($url)->toImgTag(__('plugins.generic.conferenceManager.checkin.ticketAlt'), '48mm');
    }

    /**
     * CRT-07: public check of a certificate by the code in its QR link.
     * At most 30 checks a minute from one address.
     */
    public function verify($args, $request)
    {
        $context = $request->getContext();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $code = strtolower(trim((string) ($args[0] ?? $request->getUserVar('code') ?? '')));
        $limited = false;
        $result = null;
        if ($code !== '') {
            $limiter = new \Illuminate\Cache\RateLimiter(app('cache')->store());
            $key = 'conferenceManager.verify.' . sha1((string) $request->getRemoteAddr());
            if ($limiter->tooManyAttempts($key, 30)) {
                $limited = true;
                header('Retry-After: ' . $limiter->availableIn($key));
                self::status(429);
            } else {
                $limiter->hit($key, 60);
                $result = ServiceFactory::certificates()->verify($code, Locale::getLocale());
                if ($result === null) {
                    self::status(404);
                }
            }
        }
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->addHeader('conferenceVerifyNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        $templateMgr->assign([
            'verifyCode' => $code,
            'verifyResult' => $result,
            'verifyLimited' => $limited,
            'verifyUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'verify'),
            'pageTitle' => 'plugins.generic.conferenceManager.verify.title',
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/verify.tpl'));
    }

    /**
     * CRT-08, JUR-02: the public register of professional development
     * documents the journal issued (only where the jurisdiction has them).
     */
    public function documents($args, $request)
    {
        $context = $request->getContext();
        if ($context === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $info = ContextGateway::fromContext($context);
        $service = ServiceFactory::certificates();
        if (!$service->professionalDevelopmentAllowed($info)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $perPage = 50;
        $page = max(1, (int) $request->getUserVar('page'));
        $list = $service->publicList($info, $page, $perPage);
        $pages = max(1, (int) ceil($list['itemsMax'] / $perPage));
        $url = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), self::PAGE, 'documents');
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $this->plugin->addPublicStyles($templateMgr, $request);
        $templateMgr->assign([
            'documents' => $list['items'],
            'documentsTotal' => $list['itemsMax'],
            'documentsPage' => min($page, $pages),
            'documentsPages' => $pages,
            'documentsUrl' => $url,
            'pageTitle' => 'plugins.generic.conferenceManager.documents.title',
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/documents.tpl'));
    }

    /**
     * PRG-01, PRG-04: `/{journal}/conference/{edition}/program`. Public once
     * the journal publishes the program; managers see a preview before
     * that. With sessions the program goes by days and time; without
     * them, by section. `program/pdf` gives the same as a PDF (PRG-06).
     */
    private function program(Edition $edition, ContextInfo $info, \APP\core\Request $request, bool $pdf = false): mixed
    {
        $public = ProgramService::isPublic($edition);
        $manager = $this->canManage($request);
        if (!$public && !$manager) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $locale = Locale::getLocale();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dispatcher = $request->getDispatcher();
        $contextPath = (string) $request->getContext()?->getPath();
        $articleUrl = fn (int $id): string => $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, 'article', 'view', [$id]);
        $programUrl = $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path, ['program']);

        // PRG-09: the signed-in person's own program.
        $user = $pdf ? null : $request->getUser();
        $mine = [];
        if ($user !== null && $public) {
            $personal = ServiceFactory::personalProgram();
            if ($request->isPost() && $request->checkCSRF()) {
                $sid = (int) $request->getUserVar('sessionId');
                $personal->pick($info, (int) $edition->id, $sid, (int) $user->getId(), $request->getUserVar('pick') === '1', $now);
                $request->redirectUrl($programUrl . ($request->getUserVar('mine') ? '?mine=1' : '') . '#cm-session-' . $sid);
            }
            $mine = $personal->mine($edition, (int) $user->getId(), $this->ownParticipantIds($info, (int) $user->getId(), (string) $user->getEmail()));
        }
        $onlyMine = $user !== null && $public && (bool) $request->getUserVar('mine');

        $days = ServiceFactory::schedule()->days($info, $edition, $locale, !$pdf && ($manager || $this->isRegistered($info, $edition, $request)), $now);
        $dayFormatter = class_exists(\IntlDateFormatter::class) ? new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $edition->timezone) : null;
        $numbers = class_exists(\NumberFormatter::class) ? new \NumberFormatter($locale, \NumberFormatter::DECIMAL) : null;
        foreach ($days as $d => &$day) {
            $date = new DateTimeImmutable($day['date'] . ' 12:00', new DateTimeZone($edition->timezone));
            $label = $dayFormatter?->format($date);
            $day['label'] = is_string($label) ? $label : $day['date'];
            $day['anchor'] = 'cm-day-' . ($d + 1);
            foreach ($day['sessions'] as &$session) {
                $reasons = $mine[$session['id']] ?? [];
                $session['mine'] = $reasons;
                $session['picked'] = in_array(\APP\plugins\generic\conferenceManager\classes\Application\PersonalProgramService::REASON_PICKED, $reasons, true);
                $session['mineText'] = implode(', ', array_map(static fn (string $r): string => __('plugins.generic.conferenceManager.myProgram.reason.' . $r), array_diff($reasons, [\APP\plugins\generic\conferenceManager\classes\Application\PersonalProgramService::REASON_PICKED])));
                $session['typeLabel'] = __($session['labelKey']);
                $session['chairsText'] = implode(', ', $session['chairs']);
                $session['icsUrl'] = $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path, ['program', 'ics', $session['id']]);
                if ($session['ectsX100'] !== null) {
                    $ects = $session['ectsX100'] / 100;
                    $numbers?->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
                    $session['ects'] = $numbers?->format($ects) ?: (string) $ects;
                }
                if (($session['stream']['from'] ?? null) !== null) {
                    $session['stream']['fromLabel'] = $this->formatDate(new DateTimeImmutable($session['stream']['from']), $edition->timezone, $locale);
                }
                foreach ($session['items'] as &$item) {
                    $item['url'] = $item['published'] ? $articleUrl((int) $item['submissionId']) : null;
                }
                unset($item);
            }
            unset($session);
        }
        unset($day);
        if ($onlyMine) {
            foreach ($days as $d => $day) {
                $days[$d]['sessions'] = array_values(array_filter($day['sessions'], static fn (array $s): bool => $s['mine'] !== []));
            }
            $days = array_values(array_filter($days, static fn (array $day): bool => $day['sessions'] !== []));
        }

        $program = ['sections' => [], 'count' => 0];
        if ($days === [] && !$onlyMine) {
            $program = ServiceFactory::program()->program($info, (int) $edition->id, $locale) ?? $program;
            foreach ($program['sections'] as &$section) {
                foreach ($section['groups'] as &$group) {
                    $group['label'] = __($group['labelKey']);
                    foreach ($group['items'] as &$item) {
                        $item['url'] = $item['published'] ? $articleUrl((int) $item['submissionId']) : null;
                    }
                    unset($item);
                }
                unset($group);
            }
            unset($section);
        }
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $vm = $this->viewModel($edition, $locale, $info->primaryLocale, $request);
        $templateMgr->assign([
            'edition' => $vm,
            'program' => $program,
            'programDays' => $days,
            'programTimezone' => $edition->timezone,
            'programIntro' => (string) $edition->text('programText')->resolve($locale, $info->primaryLocale),
            'programPreview' => !$public,
            'programIcsUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path, ['program', 'ics']),
            'programPdfUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path, ['program', 'pdf']),
            'programUrl' => $programUrl,
            'programSignedIn' => $user !== null && $public,
            'programOnlyMine' => $onlyMine,
            'programMineCount' => count($mine),
            'programMineIcsUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path, ['program', 'ics', 'mine']),
            'editionUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path),
            'pageTitleTranslated' => __('plugins.generic.conferenceManager.program.title') . ': ' . $vm['name'],
        ]);
        if ($pdf) {
            $html = $templateMgr->fetch($this->plugin->getTemplateResource('frontend/programPdf.tpl'));
            $content = (new \APP\plugins\generic\conferenceManager\classes\Core\MpdfRenderer())->render($html, __('plugins.generic.conferenceManager.program.title') . ': ' . $vm['name']);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="program-' . $edition->path . '.pdf"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: ' . ($public ? 'public, max-age=300' : 'private, no-store'));
            echo $content;
            exit;
        }
        $base = $request->getBaseUrl() . '/' . $this->plugin->getPluginPath();
        $this->plugin->addPublicStyles($templateMgr, $request);
        if ($days !== []) {
            $templateMgr->addJavaScript('conferenceProgramTime', $base . '/js/programTime.js', ['contexts' => 'frontend']);
        }
        if (!$public) {
            $templateMgr->addHeader('conferenceEditionNoIndex', '<meta name="robots" content="noindex">', ['contexts' => 'frontend']);
        }
        return $templateMgr->display($this->plugin->getTemplateResource('frontend/program.tpl'));
    }

    /** PRG-06: `program/ics` (all sessions) and `program/ics/{session}`. */
    private function programIcs(Edition $edition, ContextInfo $info, \APP\core\Request $request, ?int $sessionId, bool $mine = false): mixed
    {
        $public = ProgramService::isPublic($edition);
        if (!$public && !$this->canManage($request)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $only = null;
        if ($mine) {
            // PRG-09: the person's own program; private, so never cached.
            $user = $request->getUser() ?? throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
            $only = array_keys(ServiceFactory::personalProgram()->mine($edition, (int) $user->getId(), $this->ownParticipantIds($info, (int) $user->getId(), (string) $user->getEmail())));
            $public = false;
        }
        $contextPath = (string) $request->getContext()?->getPath();
        $programUrl = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path, ['program']);
        $ics = ServiceFactory::schedule()->ical($info, $edition, Locale::getLocale(), $sessionId, $programUrl, (string) parse_url($request->getBaseUrl(), PHP_URL_HOST), new DateTimeImmutable('now', new DateTimeZone('UTC')), $only);
        if ($ics === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $edition->path . ($sessionId !== null ? '-session-' . $sessionId : '') . ($mine ? '-mine' : '') . '.ics"');
        header('Content-Length: ' . strlen($ics));
        header('Cache-Control: ' . ($public ? 'public, max-age=300' : 'private, no-store'));
        echo $ics;
        exit;
    }

    /** PRG-05: the viewer takes part in the edition (an active participation). */
    private function isRegistered(ContextInfo $info, Edition $edition, PKPRequest $request): bool
    {
        $user = $request->getUser();
        if ($user === null) {
            return false;
        }
        foreach (ServiceFactory::participantRepository()->ofPerson($info->id, (int) $user->getId(), (string) $user->getEmail()) as $p) {
            if ($p['editionId'] === $edition->id && $p['status'] === \APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus::Active->value) {
                return true;
            }
        }
        return false;
    }

    /**
     * The status of a page response. OJS sends its cookies with the status
     * of the Laravel response object, so both are set.
     */
    private static function status(int $code): void
    {
        http_response_code($code);
        app()->get(\Illuminate\Http\Response::class)->setStatusCode($code);
    }

    /** @return list<int> */
    private function ownParticipantIds(\APP\plugins\generic\conferenceManager\classes\Application\ContextInfo $info, int $userId, string $email): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ids = array_map(static fn (array $p): int => (int) $p['participantId'], ServiceFactory::participants()->myParticipations($info, $userId, $email, Locale::getLocale(), $now));
        return array_values(array_unique($ids));
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
            'programUrl' => ProgramService::isPublic($edition) ? $dispatcher->url($request, Application::ROUTE_PAGE, $contextPath, self::PAGE, $edition->path, ['program']) : null,
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
    /**
     * @param list<array{role: string, partners: list<array{name: string, url: string, logoUrl: ?string, receivesFees: bool}>}> $partners INS-04
     */
    private function jsonLd(Edition $edition, array $vm, PKPRequest $request, array $partners = []): array
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
        // INS-04: co-organisers join the journal as organizers; sponsors are named too.
        $orgs = ['co_organizer' => [], 'sponsor' => []];
        foreach ($partners as $group) {
            foreach (isset($orgs[$group['role']]) ? $group['partners'] : [] as $p) {
                $orgs[$group['role']][] = array_filter(['@type' => 'Organization', 'name' => $p['name'], 'url' => $p['url'], 'logo' => $p['logoUrl']], static fn ($v) => $v !== null && $v !== '');
            }
        }
        if ($orgs['co_organizer'] !== []) {
            $data['organizer'] = [$data['organizer'], ...$orgs['co_organizer']];
        }
        if ($orgs['sponsor'] !== []) {
            $data['sponsor'] = count($orgs['sponsor']) === 1 ? $orgs['sponsor'][0] : $orgs['sponsor'];
        }
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

    /**
     * 'active' or 'waitlisted' when the signed-in person already takes part
     * in the edition, null otherwise.
     *
     * @param \APP\core\Request $request
     */
    private function myStatusIn(\APP\plugins\generic\conferenceManager\classes\Application\ContextInfo $info, string $editionPath, $request, string $locale): ?string
    {
        $user = $request->getUser();
        if ($user === null) {
            return null;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $found = null;
        foreach (ServiceFactory::participants()->myParticipations($info, (int) $user->getId(), (string) $user->getEmail(), $locale, $now) as $p) {
            if ((string) $p['editionPath'] === $editionPath && in_array($p['status'], ['active', 'waitlisted'], true)) {
                $found = $found === 'active' ? 'active' : (string) $p['status'];
            }
        }
        return $found;
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
