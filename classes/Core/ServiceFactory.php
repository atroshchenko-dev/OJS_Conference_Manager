<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\InstitutionProfileService;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\AuditLogger;
use APP\plugins\generic\conferenceManager\classes\Infrastructure\Repositories\DbInstitutionRepository;
use APP\plugins\generic\conferenceManager\classes\Jurisdiction\JurisdictionRegistry;
use Illuminate\Support\Facades\DB;
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
}
