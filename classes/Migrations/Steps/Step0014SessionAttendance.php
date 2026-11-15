<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * ATT-01, ATT-02 per session of the program; CRT-05: hours of the
 * seminars a person attended. A row means "present at the session".
 */
final class Step0014SessionAttendance implements SchemaStep
{
    public function id(): string
    {
        return '0014_session_attendance';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if ($schema->hasTable('conf_session_attendance')) {
            return;
        }
        $schema->create('conf_session_attendance', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->bigInteger('session_id')->unsigned();
            $t->foreign('session_id', 'conf_session_attendance_session_fk')
                ->references('id')->on('conf_sessions')->cascadeOnDelete();
            $t->bigInteger('participation_id')->unsigned();
            $t->foreign('participation_id', 'conf_session_attendance_participation_fk')
                ->references('id')->on('conf_participations')->cascadeOnDelete();
            $t->integer('minutes')->nullable();
            $t->string('source', 16);
            // A plain id like conf_audit_log.actor_user_id.
            $t->bigInteger('marked_by')->nullable();
            $t->dateTime('marked_at');
            $t->unique(['session_id', 'participation_id'], 'conf_session_attendance_unique');
            $t->index('participation_id', 'conf_session_attendance_participation');
        });
    }
}
