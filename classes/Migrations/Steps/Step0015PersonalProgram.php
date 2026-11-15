<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** PRG-09: sessions a user put into their own program. */
final class Step0015PersonalProgram implements SchemaStep
{
    public function id(): string
    {
        return '0015_personal_program';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if ($schema->hasTable('conf_personal_program')) {
            return;
        }
        $schema->create('conf_personal_program', function (Blueprint $t) {
            $t->bigIncrements('id');
            // A plain id like conf_audit_log.actor_user_id; merged and removed with the account.
            $t->bigInteger('user_id');
            $t->bigInteger('session_id')->unsigned();
            $t->foreign('session_id', 'conf_personal_program_session_fk')
                ->references('id')->on('conf_sessions')->cascadeOnDelete();
            $t->dateTime('created_at');
            $t->unique(['user_id', 'session_id'], 'conf_personal_program_unique');
            $t->index('session_id', 'conf_personal_program_session');
        });
    }
}
