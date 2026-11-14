<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Entry point used by OJS for install and upgrade.
 *
 * Why steps are discovered from the file system instead of listed here:
 * during a plugin upgrade the request has usually already loaded the OLD
 * version of this class (Plugin::register() calls getInstallMigration()).
 * PHP cannot reload a class, so a hard-coded list would miss new steps.
 * The directory, however, already contains the new files, and new step
 * classes have names the old request has never loaded. Keep this class
 * stable: changes here only take effect one release later.
 */
class ConferenceSchemaMigration extends Migration
{
    public const STEPS_TABLE = 'conf_schema_steps';

    private ?Connection $explicitConnection = null;

    /** Installer and <migration> attributes when run from upgrade.xml (kept for diagnostics). */
    private readonly mixed $installer;

    /** @var array<string, mixed> */
    private readonly array $attributes;

    /**
     * OJS calls `new $class($installer, $attributes)` for <migration> nodes of
     * upgrade.xml, and `new $class()` from getInstallMigration(); both are fine.
     *
     * @param mixed $installer \PKP\install\Installer when run from upgrade.xml
     * @param array<string, mixed> $attributes
     */
    public function __construct(mixed $installer = null, array $attributes = [])
    {
        $this->installer = $installer;
        $this->attributes = $attributes;
    }

    /**
     * True when OJS runs this migration from a <migration> node of upgrade.xml.
     */
    public function isRunByUpgradeDescriptor(): bool
    {
        return $this->installer !== null || $this->attributes !== [];
    }

    /**
     * Use an explicit connection (tests, tools) instead of the OJS default.
     */
    public function usingConnection(Connection $connection): static
    {
        $this->explicitConnection = $connection;
        return $this;
    }

    public function up(): void
    {
        $db = $this->explicitConnection ?? DB::connection();
        $schema = $db->getSchemaBuilder();

        if (!$schema->hasTable(self::STEPS_TABLE)) {
            $schema->create(self::STEPS_TABLE, function (Blueprint $t) {
                $t->string('step', 100)->primary();
                $t->dateTime('applied_at');
            });
        }

        foreach (self::discoverSteps() as $step) {
            $step->up($schema, $db);
            $exists = $db->table(self::STEPS_TABLE)->where('step', $step->id())->exists();
            if (!$exists) {
                $db->table(self::STEPS_TABLE)->insert(['step' => $step->id(), 'applied_at' => gmdate('Y-m-d H:i:s')]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately empty: financial records and certificate registers
        // are never dropped automatically (plan 7.3, ADR-5).
    }

    /**
     * @return list<SchemaStep>
     */
    public static function discoverSteps(): array
    {
        $files = glob(__DIR__ . '/Steps/Step*.php') ?: [];
        sort($files, SORT_STRING);
        $steps = [];
        foreach ($files as $file) {
            $class = __NAMESPACE__ . '\\Steps\\' . basename($file, '.php');
            if (!class_exists($class)) {
                require_once $file;
            }
            $step = new $class();
            if (!$step instanceof SchemaStep) {
                throw new RuntimeException("{$class} must implement SchemaStep");
            }
            $steps[] = $step;
        }
        return $steps;
    }
}
