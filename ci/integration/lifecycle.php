<?php

/**
 * Plugin lifecycle check inside a real OJS 3.5 installation.
 *
 * Usage (from the OJS root):
 *   php lifecycle.php install <package.tar.gz>
 *   php lifecycle.php upgrade <package.tar.gz>
 *   php lifecycle.php context <path> <primaryLocale>      create a journal if missing
 *   php lifecycle.php enable <contextPath>
 *   php lifecycle.php migrate-twice
 *   php lifecycle.php assert-tables <table>[,<table>…]
 *   php lifecycle.php assert-column <table> <column>
 *   php lifecycle.php steps
 *
 * install / upgrade call the same PluginHelper methods as the upload form
 * of the plugin grid. upgrade loads the plugin first, exactly like the
 * upgrade request does, to reproduce "old classes already loaded".
 */

declare(strict_types=1);

require getcwd() . '/tools/bootstrap.php';

use APP\core\Application;
use Illuminate\Support\Facades\DB;
use PKP\plugins\PluginHelper;
use PKP\plugins\PluginRegistry;

class ConferenceLifecycleTool extends \PKP\cliTool\CommandLineTool
{
    public function execute(): void
    {
        $cmd = $this->argv[0] ?? '';
        try {
            match ($cmd) {
                'install' => $this->install($this->argv[1]),
                'upgrade' => $this->upgrade($this->argv[1]),
                'context' => $this->context($this->argv[1], $this->argv[2] ?? 'uk'),
                'enable' => $this->enable($this->argv[1]),
                'migrate-twice' => $this->migrateTwice(),
                'assert-tables' => $this->assertTables(explode(',', $this->argv[1])),
                'assert-column' => $this->assertColumn($this->argv[1], $this->argv[2]),
                'steps' => $this->steps(),
                default => throw new RuntimeException("Unknown command {$cmd}"),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, "FAIL {$cmd}: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            exit(1);
        }
    }

    private function copyToTemp(string $package): string
    {
        $tmp = sys_get_temp_dir() . '/' . uniqid('cm', true) . '.tar.gz';
        copy($package, $tmp) || throw new RuntimeException('cannot copy package');
        return $tmp;
    }

    private function install(string $package): void
    {
        $version = (new PluginHelper())->installPlugin($this->copyToTemp($package), basename($package));
        echo 'OK installed ', $version->getVersionString(false), "\n";
    }

    private function upgrade(string $package): void
    {
        // The real upgrade request has the old plugin registered already.
        $plugin = PluginRegistry::loadPlugin('generic', 'conferenceManager');
        $plugin?->getInstallMigration();
        $version = (new PluginHelper())->upgradePlugin('generic', 'conferenceManager', $this->copyToTemp($package), basename($package));
        echo 'OK upgraded to ', $version->getVersionString(false), "\n";
    }

    private function context(string $path, string $locale): void
    {
        $dao = Application::getContextDAO();
        if ($dao->getByPath($path)) {
            echo "OK context {$path} exists\n";
            return;
        }
        $context = $dao->newDataObject();
        $context->setData('urlPath', $path);
        $context->setData('name', ['en' => "Test conferences {$path}", 'uk' => "Тестові конференції {$path}"]);
        $context->setData('primaryLocale', $locale);
        $context->setData('supportedLocales', ['uk', 'en']);
        $context->setData('supportedFormLocales', ['uk', 'en']);
        $context->setData('supportedSubmissionLocales', ['uk', 'en']);
        $context->setData('contactEmail', "{$path}@example.org");
        $context->setData('contactName', 'Org committee');
        $context->setData('enabled', true);
        $request = Application::get()->getRequest();
        $context = app()->get('context')->add($context, $request);
        echo 'OK context ', $path, ' id=', $context->getId(), "\n";
    }

    private function enable(string $path): void
    {
        $context = Application::getContextDAO()->getByPath($path) ?? throw new RuntimeException('no context');
        $plugin = PluginRegistry::loadPlugin('generic', 'conferenceManager', $context->getId())
            ?? throw new RuntimeException('plugin not found');
        $plugin->updateSetting($context->getId(), 'enabled', true, 'bool');
        echo 'OK enabled in ', $path, "\n";
    }

    private function migrateTwice(): void
    {
        $class = 'APP\\plugins\\generic\\conferenceManager\\classes\\Migrations\\ConferenceSchemaMigration';
        $before = DB::table('conf_schema_steps')->count();
        (new $class())->up();
        (new $class())->up();
        $after = DB::table('conf_schema_steps')->count();
        if ($before !== $after) {
            throw new RuntimeException("steps changed {$before} -> {$after}");
        }
        echo "OK migration up() twice, steps={$after}\n";
    }

    /** @param list<string> $tables */
    private function assertTables(array $tables): void
    {
        foreach ($tables as $t) {
            DB::getSchemaBuilder()->hasTable($t) || throw new RuntimeException("missing table {$t}");
        }
        echo 'OK tables: ', implode(', ', $tables), "\n";
    }

    private function assertColumn(string $table, string $column): void
    {
        DB::getSchemaBuilder()->hasColumn($table, $column) || throw new RuntimeException("missing column {$table}.{$column}");
        echo "OK column {$table}.{$column}\n";
    }

    private function steps(): void
    {
        foreach (DB::table('conf_schema_steps')->orderBy('step')->get() as $r) {
            echo $r->step, ' ', $r->applied_at, "\n";
        }
    }
}

(new ConferenceLifecycleTool($argv ?? []))->execute();
