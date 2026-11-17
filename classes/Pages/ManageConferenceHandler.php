<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Pages;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\conferenceManager\classes\Core\ContextGateway;
use APP\plugins\generic\conferenceManager\classes\Core\ServiceFactory;
use APP\plugins\generic\conferenceManager\classes\Core\UserGroupGateway;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\Edition;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EditionStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventFormat;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\EventType;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\PublicationMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\ReviewMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\SubmissionMode;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConfirmationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipantRole;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ParticipationStatus;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PaymentFilter;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\PresentationType;
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

    /** Strings of js/participantsManager.js; also under the plugin prefix. */
    private const FEE_STRINGS = [
        'fee.title', 'fee.saved', 'fee.categories', 'fee.categories.description', 'fee.categories.empty', 'fee.category', 'fee.category.name',
        'fee.category.code', 'fee.category.code.description', 'fee.category.add', 'fee.requiresProof', 'fee.presets',
        'fee.deleteCategoryConfirm', 'fee.deleteTariffConfirm', 'fee.tariffs', 'fee.tariffs.description', 'fee.tariffs.empty',
        'fee.tariff.add', 'fee.material', 'fee.presentationType', 'fee.anyType', 'fee.pricing', 'fee.pages', 'fee.minPages',
        'fee.maxPages', 'fee.amount', 'fee.amount.description', 'fee.currency', 'fee.period', 'fee.validFrom', 'fee.validTo',
        'fee.always', 'fee.free', 'fee.perPageShort', 'fee.quote', 'fee.quote.run', 'fee.quote.noTariff',
        'fee.quote.pagesRequired', 'fee.quote.error',
        'participants.title', 'participants.search', 'participants.role', 'participants.category', 'participants.noCategory',
        'participants.confirmation', 'participants.status', 'participants.all', 'participants.find', 'participants.export',
        'participants.empty', 'participants.minor', 'participants.payment', 'participants.count', 'participants.name', 'participants.cancel', 'participants.cancelConfirm',
        'participants.cancelled', 'participants.page',
        'payment.title', 'payment.rules', 'payment.rules.description', 'payment.rules.empty', 'payment.rule.add', 'payment.rule.default',
        'payment.rule.deleteConfirm', 'payment.mode', 'payment.account', 'payment.account.none', 'payment.externalUrl',
        'payment.instructions', 'payment.instructions.description',
        'invoiceTemplate.title', 'payer.taxIdSetting', 'payer.taxIdSettingHelp', 'invoiceTemplate.description', 'invoiceTemplate.kind.bank', 'invoiceTemplate.kind.treasury',
        'invoiceTemplate.custom', 'invoiceTemplate.builtIn', 'invoiceTemplate.variables', 'invoiceTemplate.reset',
        'invoice.title', 'invoice.search', 'invoice.empty', 'invoice.number', 'invoice.payer', 'invoice.total', 'invoice.paidPart',
        'invoice.markPaid', 'export.title', 'export.description', 'export.period', 'export.scope.edition', 'export.scope.all', 'export.from', 'export.to', 'export.dateField', 'export.dateField.issued', 'export.dateField.paid', 'export.withReplaced', 'export.columns', 'export.addColumn', 'export.up', 'export.down', 'export.remove', 'export.csv', 'export.xlsx', 'export.saveColumns', 'export.columnsSaved', 'invoice.reissue', 'invoice.reissuePrompt', 'invoice.reissueDone', 'invoice.waive', 'invoice.history', 'invoice.paidPrompt', 'invoice.paidDone', 'invoice.waivePrompt',
        'invoice.waiveDone', 'invoice.cancelConfirm', 'invoice.cancelDone', 'invoice.issue', 'invoice.issued', 'invoice.pagesPrompt',
        'invoice.event.issued', 'invoice.event.part_paid', 'invoice.event.paid', 'invoice.event.waived', 'invoice.event.superseded',
        'invoice.event.cancelled', 'invoice.event.status_cancelled', 'invoice.event.status_refund_requested',
        'invoice.event.proof_uploaded', 'invoice.event.proof_accepted', 'invoice.event.proof_rejected',
        'invite.open', 'invite.title', 'invite.help', 'invite.email', 'invite.givenName', 'invite.familyName', 'invite.affiliation', 'invite.note', 'invite.sendLetter', 'invite.submit', 'invite.sent', 'invite.saved', 'invite.badge', 'group.open', 'group.title', 'group.help', 'group.selected', 'group.organization', 'group.legalId', 'group.contact', 'group.contactHelp', 'group.submit', 'group.refusedTitle', 'group.issued', 'group.select', 'group.selectOne', 'group.badge', 'refund.request', 'refund.requestPrompt', 'refund.requestDone', 'refund.record', 'refund.amountPrompt', 'refund.notePrompt', 'refund.done', 'refund.refunded', 'invoice.event.refund_requested', 'invoice.event.refunded', 'invoice.event.part_refunded',
        'proof.title', 'proof.tab.pending', 'proof.status.pending', 'proof.status.accepted', 'proof.status.rejected', 'proof.emptyPending', 'proof.empty',
        'proof.due', 'proof.file', 'proof.duplicate', 'proof.view', 'proof.accept', 'proof.reject', 'proof.acceptDone', 'proof.rejectPrompt',
        'proof.rejectDone',
        'journal.title', 'journal.description', 'journal.empty', 'journal.time', 'journal.event', 'journal.amount', 'journal.note',
        'decision.bulk.title', 'decision.bulk.description', 'decision.bulk.progress', 'decision.bulk.failedCount', 'decision.bulk.finished',
        'decision.bulk.search', 'decision.bulk.type', 'decision.bulk.anyType', 'decision.bulk.section', 'decision.bulk.anySection',
        'decision.bulk.find', 'decision.bulk.selected', 'decision.bulk.decision', 'decision.bulk.choose', 'decision.bulk.run',
        'decision.bulk.confirm', 'decision.bulk.tooMany', 'decision.bulk.empty', 'decision.bulk.selectAll', 'decision.bulk.column.title',
        'decision.bulk.column.section', 'decision.bulk.column.type', 'decision.bulk.runs',
        'attendance.title', 'attendance.present', 'attendance.absent', 'attendance.import', 'attendance.importHelp', 'attendance.file',
        'attendance.threshold', 'attendance.preview', 'attendance.apply', 'attendance.resultPreview', 'attendance.resultApplied',
        'attendance.minutes', 'attendance.minutesShort', 'attendance.keptManual', 'attendance.unmatched', 'attendance.fromImport',
        'attendance.marked', 'attendance.markPage', 'attendance.unmarkPage', 'attendance.chooseFile', 'attendance.applied',
        'certificate.title', 'certificate.help', 'certificate.summary', 'certificate.show', 'certificate.show.all', 'certificate.show.eligible',
        'certificate.show.issued', 'certificate.show.none', 'certificate.issue', 'certificate.issueConfirm', 'certificate.issued',
        'certificate.chooseAll', 'certificate.hoursShort', 'attendance.sessionsShort', 'certificate.type', 'certificate.number', 'certificate.templates', 'certificate.series', 'certificate.seriesHelp',
        'certificate.template.custom', 'certificate.template.builtIn', 'certificate.template.html', 'certificate.template.help',
        'certificate.template.preview', 'certificate.template.reset', 'certificate.template.resetConfirm',
        'certificate.issueAll', 'certificate.queued', 'certificate.addReviewers', 'certificate.reviewersAdded', 'certificate.vectorNew',
        'certificate.vectorAll', 'certificate.vectorEnabled', 'certificate.issuedList', 'certificate.state', 'certificate.valid', 'certificate.revoked',
        'certificate.replacedBy', 'certificate.reissue', 'certificate.revoke', 'certificate.revokePrompt', 'certificate.reissuePrompt',
        'certificate.revokedDone', 'certificate.reissuedDone', 'certificate.issuedAt',
        'dashboard.title', 'dashboard.refresh', 'dashboard.submissions', 'dashboard.waiting', 'dashboard.review', 'dashboard.accepted',
        'dashboard.declined', 'dashboard.published', 'dashboard.fullText', 'dashboard.byType', 'dashboard.participants', 'dashboard.active',
        'dashboard.cancelled', 'dashboard.payments', 'dashboard.noInvoices', 'dashboard.paid', 'dashboard.open', 'dashboard.due',
        'dashboard.partPaid', 'dashboard.waived', 'dashboard.replaced', 'dashboard.pendingProofs', 'dashboard.reminders', 'scheduler.ok', 'scheduler.stale', 'scheduler.never', 'report.title', 'report.by', 'report.csv', 'report.empty', 'report.total', 'invitationLetter.short',
        'campaign.title', 'campaign.description', 'campaign.progress', 'campaign.failedCount', 'campaign.finished', 'campaign.recipients',
        'campaign.section', 'campaign.accepted', 'campaign.fullText', 'campaign.fullText.yes', 'campaign.fullText.no', 'campaign.preview',
        'campaign.count', 'campaign.tooMany', 'campaign.subject', 'campaign.body', 'campaign.variables', 'campaign.test', 'campaign.send',
        'campaign.testSent', 'campaign.confirm', 'campaign.history',
        'reminders.title', 'reminders.description', 'reminders.payment', 'reminders.paper', 'reminders.event', 'reminders.payment.description',
        'reminders.paper.description', 'reminders.event.description', 'reminders.enabled', 'reminders.days', 'reminders.saved',
        'privacy.title', 'privacy.retention', 'privacy.retentionHelp', 'privacy.proofsMonths', 'privacy.participantsMonths', 'privacy.person',
        'privacy.personHelp', 'privacy.email', 'privacy.export', 'privacy.anonymize', 'privacy.anonymizeConfirm', 'privacy.anonymized',
        'audit.title', 'audit.log', 'audit.consent', 'audit.description', 'audit.entity', 'audit.empty', 'audit.actor', 'audit.action',
        'audit.system', 'audit.details', 'audit.before', 'audit.after', 'audit.consent.description', 'audit.consent.empty',
        'audit.consent.agreements', 'audit.entity.edition', 'audit.entity.invoice', 'audit.entity.participant', 'audit.entity.participation',
        'audit.entity.submission', 'audit.entity.submission_registration', 'audit.entity.campaign', 'audit.entity.reminders',
        'audit.entity.export', 'audit.entity.export_columns', 'audit.entity.fee_category', 'audit.entity.tariff', 'audit.entity.payment_rule', 'audit.entity.retention', 'audit.entity.personal_data', 'audit.entity.user', 'audit.entity.certificate', 'audit.entity.certificate_settings', 'audit.entity.certificate_template', 'audit.entity.invoice_settings', 'audit.entity.invoice_template',
        'program.builder.open', 'program.builder.title', 'program.builder.preview', 'program.builder.help', 'program.conflicts', 'program.conflict', 'program.rooms', 'program.room.name', 'program.room.capacity', 'program.room.onlineUrl', 'program.room.add', 'program.room.deleteConfirm', 'program.session.add', 'program.session.addDay', 'program.session.edit', 'program.session.type', 'program.session.title', 'program.session.room', 'program.session.noRoom', 'program.session.startsAt', 'program.session.endsAt', 'program.session.slot', 'program.session.chairs', 'program.session.findChair', 'program.session.removeChair', 'program.session.description', 'program.session.streamUrl', 'program.session.streamVisibleFrom', 'program.session.streamHelp', 'program.session.hours', 'program.session.attendance', 'program.session.ects', 'program.session.outcomes', 'program.session.deleteConfirm', 'program.session.conflict', 'program.session.roomClash', 'program.session.overrun', 'program.session.plenary', 'program.session.section', 'program.session.poster', 'program.session.seminar', 'program.session.break', 'program.unassigned', 'program.unassigned.empty', 'program.sessions.empty', 'program.moveTo', 'program.takeOut', 'program.dropHere', 'program.dropFirst', 'program.notAccepted', 'program.duration', 'program.minutes', 'program.up', 'program.down', 'program.fix', 'program.saved', 'audit.entity.program_room', 'audit.entity.program_session', 'audit.entity.session_attendance',
        'legacy.title', 'legacy.help', 'legacy.stats', 'legacy.import', 'legacy.importHelp', 'legacy.file', 'legacy.paste', 'legacy.importButton', 'legacy.imported', 'legacy.errors', 'legacy.ambiguous', 'legacy.ambiguousHelp', 'legacy.unmatched', 'legacy.unmatchedHelp', 'legacy.check', 'legacy.oldUrl', 'legacy.checkButton', 'legacy.notOcs', 'legacy.leadsTo', 'legacy.noArticle', 'legacy.map', 'legacy.mapHelp', 'legacy.mapNginx', 'legacy.mapApache', 'legacy.mapApacheRules', 'legacy.templates', 'legacy.templatesHelp', 'legacy.restore', 'legacy.clear', 'legacy.clearHelp', 'legacy.clearConfirm', 'legacy.cleared', 'audit.entity.legacy_urls',
        'partner.open', 'partner.title', 'partner.help', 'partner.empty', 'partner.logoOf', 'partner.noLogo', 'partner.receivesFees', 'partner.moveUp', 'partner.moveDown', 'partner.replaceLogo', 'partner.uploadLogo', 'partner.removeLogo', 'partner.deleteConfirm', 'partner.logoHelp', 'partner.logoSaved', 'partner.add', 'partner.limit', 'partner.role', 'partner.name', 'partner.url', 'partner.account', 'partner.noAccount', 'partner.accountHelp', 'partner.noAccounts', 'audit.entity.partner',
        'promo.open', 'promo.title', 'promo.codes', 'promo.help', 'promo.empty', 'promo.code', 'promo.discount', 'promo.uses', 'promo.usesOf', 'promo.period', 'promo.state', 'promo.actions', 'promo.always', 'promo.on', 'promo.off', 'promo.deleteConfirm', 'promo.add', 'promo.usedHelp', 'promo.codeHelp', 'promo.percentType', 'promo.amountType', 'promo.percent', 'promo.amount', 'promo.currency', 'promo.maxUses', 'promo.unlimited', 'promo.validFrom', 'promo.validUntil', 'promo.note', 'promo.active',
        'seats.title', 'seats.help', 'seats.field', 'seats.unlimited', 'seats.noLimit', 'seats.state', 'seats.given', 'waitlist.title', 'audit.entity.promo_code',
        'bids.open', 'bids.manager', 'bids.managerHelp', 'bids.light', 'bids.until', 'bids.untilHelp', 'bids.perSubmission', 'bids.invite', 'bids.invited', 'bids.pageUrl', 'bids.reviewers', 'bids.noReviewers', 'bids.reviewer', 'bids.wanted', 'bids.load',
        'bids.proposal', 'bids.proposalHelp', 'bids.noProposal', 'bids.pick', 'bids.submission', 'bids.bid', 'bids.assign', 'bids.assigned', 'bids.bySubmission', 'bids.noSubmissions', 'bids.assignedNow', 'bids.notInReview',
        'bids.bid.eager', 'bids.bid.willing', 'bids.bid.unwilling', 'bids.bid.conflict', 'audit.entity.review_bid',
        'gateway.title', 'gateway.help', 'gateway.service', 'gateway.none', 'gateway.secretSet', 'gateway.sandbox', 'gateway.sandboxWarning', 'gateway.currencies', 'gateway.ruleHint',
        'gateway.liqpay.publicKey', 'gateway.liqpay.privateKey', 'audit.entity.payment_gateway',
        'book.open', 'book.title', 'book.help', 'book.contents', 'book.count', 'book.empty', 'book.noGroup', 'book.withoutAbstract', 'book.codes', 'book.noCodes', 'book.layout', 'book.format', 'book.order', 'book.order.program', 'book.order.sections', 'book.order.title', 'book.authorIndex', 'book.keywords', 'book.titlePage', 'book.field.header', 'book.field.title', 'book.field.subtitle', 'book.field.place', 'book.field.imprint', 'book.field.preface', 'book.hint.header', 'book.hint.title', 'book.hint.subtitle', 'book.hint.place', 'book.hint.imprint', 'book.hint.preface', 'book.download', 'book.notSaved', 'audit.entity.abstract_book',
        'review.settings.open', 'review.settings.title', 'review.settings.help', 'review.settings.notTwoPhase', 'review.settings.noForms', 'review.settings.formsLink', 'review.settings.abstractForm', 'review.settings.abstractFormHelp', 'review.settings.paperForm', 'review.settings.paperFormHelp', 'review.settings.noForm', 'review.settings.howTitle', 'review.settings.step1', 'review.settings.step2', 'review.settings.step3', 'review.settings.step4', 'review.settings.notSaved', 'review.votes', 'review.tallyShort', 'audit.entity.review_vote', 'audit.entity.review_settings',
        'audit.entity.setup', 'audit.entity.site_mode', 'siteMode.title', 'siteMode.conferenceSite', 'siteMode.description', 'siteMode.wizardNote', 'siteMode.menuItemPresent', 'siteMode.addMenuItem', 'siteMode.openPage', 'siteMode.saved', 'setup.open', 'setup.title', 'setup.intro', 'setup.step1', 'setup.step2', 'setup.step3', 'setup.type', 'setup.type.help', 'setup.next', 'setup.previous', 'setup.none', 'setup.categories', 'setup.tariffs', 'setup.pk', 'setup.vector', 'setup.sitePreset', 'setup.choose', 'setup.othersNote', 'setup.willCreate', 'setup.categoriesList', 'setup.tariffsList', 'setup.rulesList', 'setup.rule.default', 'setup.accountMissing', 'setup.timezone', 'setup.draftNote', 'setup.create', 'setup.created', 'setup.fix', 'setup.doneTitle', 'setup.doneText', 'setup.toEditions',
    ];

    /** @var bool */
    public $_isBackendPage = true;

    public function __construct(private readonly ConferenceManagerPlugin $plugin)
    {
        parent::__construct();
        // Assistant: the "Registration manager" group sees the register
        // only; other assistants are refused in index().
        $this->addRoleAssignment([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_ASSISTANT], ['index']);
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
        $context = $request->getContext();
        $user = $request->getUser();
        $groups = new UserGroupGateway($this->plugin);
        $contextId = (int) $context->getId();
        $isManager = $user !== null && ($user->hasRole([Role::ROLE_ID_MANAGER], $contextId) || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID));
        if ($user === null || (!$isManager && !$groups->canManageRegistrations($contextId, (int) $user->getId()))) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $this->setupTemplate($request);
        $info = ContextGateway::fromContext($context);
        $dispatcher = $request->getDispatcher();
        $templateMgr = TemplateManager::getManager($request);

        $base = $request->getBaseUrl() . '/' . $this->plugin->getPluginPath();
        $templateMgr->addJavaScript('conferenceEditionsManager', $base . '/js/editionsManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceParticipantsManager', $base . '/js/participantsManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceInvoicesManager', $base . '/js/invoicesManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceDecisionsManager', $base . '/js/decisionsManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceCommunicationManager', $base . '/js/communicationManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceCertificatesManager', $base . '/js/certificatesManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceProgramBuilder', $base . '/js/programBuilder.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceSetupWizard', $base . '/js/setupWizard.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceReviewSettings', $base . '/js/reviewSettings.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferencePartners', $base . '/js/partnersManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferencePromos', $base . '/js/promoManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceBidding', $base . '/js/biddingManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceAbstractBook', $base . '/js/abstractBook.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferenceLegacyUrls', $base . '/js/legacyUrls.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addJavaScript('conferencePrivacyManager', $base . '/js/privacyManager.js', ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]);
        $templateMgr->addStyleSheet('conferenceTokens', $base . '/css/backend-tokens.css', ['contexts' => 'backend']);
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
            'invoicePdfUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), ConferencePageHandler::PAGE, 'invoice', ['__id__']),
            'proofFileUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), ConferencePageHandler::PAGE, 'receipt', ['__id__']),
            'certificatePdfUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), ConferencePageHandler::PAGE, 'certificate', ['__id__']),
            'reviewFormsUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'management', 'settings', ['workflow'], null, 'review/reviewForms'),
            'attendanceThreshold' => ServiceFactory::attendance()->threshold($info),
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
                'presentationTypes' => $choices(PresentationType::cases()),
            ],
            'i18n' => self::strings(),
            'canEditEditions' => $isManager,
            // OJS 3.5 shows plugin actions on the site level only to journal
            // managers, so the site administrator reaches the overview from here.
            'siteOverviewUrl' => \PKP\security\Validation::isSiteAdmin()
                ? $dispatcher->url($request, Application::ROUTE_PAGE, 'index', \APP\plugins\generic\conferenceManager\classes\Pages\SiteOverviewHandler::PAGE)
                : '',
            'tibSync' => $isManager && ServiceFactory::editions()->tibEnabled($info),
            'initialItems' => $isManager ? null : $this->editionsForRegister($info),
            'participantOptions' => [
                'roles' => $choices(ParticipantRole::cases()),
                'statuses' => $choices(ParticipationStatus::cases()),
                'confirmations' => $choices(ConfirmationStatus::cases()),
                'payments' => $choices(PaymentFilter::cases()),
                'invitedRoles' => $choices(\APP\plugins\generic\conferenceManager\classes\Application\ParticipantService::INVITED_ROLES),
                'legalIdLabel' => __(ServiceFactory::invoices()->payerFields($info)['legalId']),
            ],
        ]]]);

        $templateMgr->assign(['pageTitle' => __('plugins.generic.conferenceManager.editions.title')]);
        return $templateMgr->display($this->plugin->getTemplateResource('manageEditions.tpl'));
    }

    /**
     * Editions shown to a registration manager, who has no access to the
     * editions API: the ones that can have participants.
     *
     * @return list<array<string, mixed>>
     */
    private function editionsForRegister(\APP\plugins\generic\conferenceManager\classes\Application\ContextInfo $info): array
    {
        $statuses = [EditionStatus::CfpOpen, EditionStatus::CfpClosed, EditionStatus::Held, EditionStatus::Published];
        $result = ServiceFactory::editions()->list($info, $statuses, 100);
        return array_map(static fn (Edition $e): array => $e->toArray() + ['submissionsOpen' => false], $result['items']);
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
            'publicPage', 'open', 'nav.overview', 'nav.people', 'nav.money', 'nav.content', 'nav.journal', 'nav.sections', 'issue', 'filterStatus', 'allStatuses', 'loading', 'errors', 'copyOf', 'copy.partsTitle', 'copy.partsHelp', 'copy.part.fees', 'copy.part.paymentRules', 'copy.part.rooms', 'copy.part.settings', 'copy.part.partners', 'copy.done',
            'section.main', 'section.modes', 'section.dates', 'section.texts', 'section.details', 'section.program', 'program.help', 'program.open', 'field.programPublished', 'field.programText',
            'field.name', 'field.path', 'field.path.description', 'field.eventType', 'field.format', 'field.timezone',
            'tibSync', 'field.venue', 'field.description', 'field.learningOutcomes', 'field.partialQualifications', 'field.cfpText', 'field.status',
            'field.submissionMode', 'field.publicationMode', 'field.externalProceedingsUrl', 'field.reviewMode',
            'field.startsAt', 'field.endsAt', 'field.cfpOpensAt', 'field.abstractDeadline', 'field.paperDeadline',
            'field.registrationClosesAt', 'field.paymentDeadline', 'field.dates.description',
            'field.isbn', 'field.udc', 'field.vectorProgramId', 'field.hours', 'field.ects',
            'column.dates', 'column.status', 'column.type', 'submissionsOpen', 'submissionsClosed', 'fees', 'participants', 'payments', 'invoices', 'proofs', 'decisions', 'back',
            'dashboard', 'campaigns', 'reminders', 'audit', 'certificates', 'certificateTemplates', 'siteOverview',
        ] as $key) {
            $out[$key] = __('plugins.generic.conferenceManager.editions.' . $key);
        }
        foreach (self::FEE_STRINGS as $key) {
            $out[$key] = __('plugins.generic.conferenceManager.' . $key);
        }
        return $out;
    }
}
