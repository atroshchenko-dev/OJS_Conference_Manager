<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * SQLite in memory with minimal stand-ins for the OJS core tables the
 * plugin's foreign keys point to.
 */
final class OjsStandIns
{
    public static function connection(): Connection
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $db = $capsule->getConnection();
        self::create($db);
        return $db;
    }

    public static function create(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->create('journals', function (Blueprint $t) {
            $t->bigInteger('journal_id')->autoIncrement();
            $t->string('path', 32);
        });
        foreach (['submissions' => 'submission_id', 'users' => 'user_id', 'authors' => 'author_id', 'issues' => 'issue_id', 'publication_galleys' => 'galley_id'] as $table => $key) {
            $schema->create($table, function (Blueprint $t) use ($key) {
                $t->bigInteger($key)->autoIncrement();
                $t->string('note', 32)->nullable();
            });
        }
        $schema->create('publication_settings', function (Blueprint $t) {
            $t->bigIncrements('publication_setting_id');
            $t->bigInteger('publication_id');
            $t->string('locale', 28)->default('');
            $t->string('setting_name', 255);
            $t->mediumText('setting_value')->nullable();
        });
        $db->table('journals')->insert([['path' => 'a'], ['path' => 'b']]);
    }
}
