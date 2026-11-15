<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use APP\plugins\generic\conferenceManager\classes\Application\EditionService;
use APP\plugins\generic\conferenceManager\classes\Application\FeeService;
use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceDocumentService;
use APP\plugins\generic\conferenceManager\classes\Application\InvoiceService;
use APP\plugins\generic\conferenceManager\classes\Application\ParticipantService;
use APP\plugins\generic\conferenceManager\classes\Application\ProofService;
use APP\plugins\generic\conferenceManager\classes\Application\SubmissionRegistrationService;
use APP\plugins\generic\conferenceManager\classes\Domain\Registration\ConsentText;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Files\LocalProofStore;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Queries\UpcomingEditionsQuery;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbEditionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbFeeRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbParticipantRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbPaymentRepository;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbRegistrationRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\plugins\Hook;

/**
 * Builds services with OJS infrastructure. Lazily collects jurisdiction
 * profiles from other plugins through the "ConferenceManager::jurisdictions"
 * hook, so a plugin registered later than ours can still add one.
 */
final class ServiceFactory
{
    private static ?JurisdictionRegistry $jurisdictions = null;

    public static function jurisdictions(): JurisdictionRegistry
    {
        if (self::$jurisdictions === null) {
            $registry = JurisdictionRegistry::withBuiltIns();
            /**
             * @hook ConferenceManager::jurisdictions [$registry]
             * Callback: fn (string $hookName, JurisdictionRegistry $registry): bool
             *   { $registry->register(new MyCountryProfile()); return Hook::CONTINUE; }
             */
            Hook::run('ConferenceManager::jurisdictions', [$registry]);
            self::$jurisdictions = $registry;
        }
        return self::$jurisdictions;
    }

    public static function institutionProfiles(): InstitutionProfileService
    {
        $db = DB::connection();
        return new InstitutionProfileService(
            new DbInstitutionRepository($db),
            self::jurisdictions(),
            new AuditLogger($db),
        );
    }

    public static function editions(): EditionService
    {
        $db = DB::connection();
        return new EditionService(
            new DbEditionRepository($db),
            self::jurisdictions(),
            new DbInstitutionRepository($db),
            new IssueGateway(),
            new AuditLogger($db),
        );
    }

    public static function upcoming(): UpcomingEditionsQuery
    {
        return new UpcomingEditionsQuery(DB::connection(), 'conferencemanagerplugin');
    }

    public static function fees(): FeeService
    {
        $db = DB::connection();
        return new FeeService(new DbFeeRepository($db), new DbEditionRepository($db), new AuditLogger($db));
    }

    public static function participants(): ParticipantService
    {
        $db = DB::connection();
        $plugin = \PKP\plugins\PluginRegistry::getPlugin('generic', 'conferencemanagerplugin');
        return new ParticipantService(
            new DbParticipantRepository($db),
            new DbEditionRepository($db),
            new DbFeeRepository($db),
            new SubmissionGateway(),
            $plugin instanceof \PKP\plugins\Plugin ? new UserGroupGateway($plugin) : null,
            new AuditLogger($db),
            static fn (string $key): string => __($key),
            self::invoices(),
        );
    }

    public static function invoices(): InvoiceService
    {
        $db = DB::connection();
        return new InvoiceService(
            new DbPaymentRepository($db),
            new DbFeeRepository($db),
            new DbEditionRepository($db),
            new DbParticipantRepository($db),
            new DbRegistrationRepository($db),
            static fn (ContextInfo $context) => self::institutionProfiles()->getOrDraft($context),
            new MailGateway(),
            new AuditLogger($db),
            static fn (string $key, array $params, string $locale): string => __($key, $params, $locale),
            self::jurisdictions(),
        );
    }

    public static function proofs(): ProofService
    {
        $files = (string) Config::getVar('files', 'files_dir');
        return new ProofService(
            new DbPaymentRepository(DB::connection()),
            self::invoices(),
            new LocalProofStore($files === '' ? '' : rtrim($files, '/') . '/conferenceManager/proofs'),
            new MailGateway(),
            new AuditLogger(DB::connection()),
            static fn (string $key, array $params, string $locale): string => __($key, $params, $locale),
        );
    }

    public static function invoiceDocuments(): InvoiceDocumentService
    {
        $plugin = \PKP\plugins\PluginRegistry::getPlugin('generic', 'conferencemanagerplugin');
        return new InvoiceDocumentService(
            self::invoices(),
            new PluginInvoiceTemplateStore($plugin instanceof \PKP\plugins\Plugin ? $plugin : null),
            new MpdfRenderer(),
            dirname(__DIR__, 2) . '/templates/invoice',
            static fn (string $key, array $params, string $locale): string => __($key, $params, $locale),
            new AuditLogger(DB::connection()),
        );
    }

    public static function registrations(): SubmissionRegistrationService
    {
        $db = DB::connection();
        return new SubmissionRegistrationService(
            new DbRegistrationRepository($db),
            new DbEditionRepository($db),
            new SubmissionGateway(),
            new MailGateway(),
            new AuditLogger($db),
            new DbFeeRepository($db),
        );
    }

    /**
     * The text of the consent (REG-02): the privacy notice of the
     * institution profile in this language, else the general text.
     */
    public static function consentText(ContextInfo $context, string $locale): ConsentText
    {
        $notice = self::institutionProfiles()->getOrDraft($context)->text('privacyNotice');
        if ($notice->isEmpty()) {
            $key = 'plugins.generic.conferenceManager.registration.consent.default';
            return new ConsentText(__($key, [], $locale), __($key, [], $context->primaryLocale));
        }
        return new ConsentText((string) $notice->resolve($locale, $context->primaryLocale), ConsentText::sourceOf($notice->toArray()));
    }
}
