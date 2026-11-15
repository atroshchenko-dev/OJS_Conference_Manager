<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Speaker questionnaire of a submission (REG-02) and the expiry of
 * co-author confirmation links (REG-04).
 */
final class Step0004SubmissionRegistrations implements SchemaStep
{
    public function id(): string
    {
        return '0004_submission_registrations';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasTable('conf_submission_registrations')) {
            $schema->create('conf_submission_registrations', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('submission_id');
                $t->foreign('submission_id', 'conf_sub_reg_submission_fk')
                    ->references('submission_id')->on('submissions')->cascadeOnDelete();
                $t->bigInteger('edition_id')->unsigned()->nullable();
                $t->foreign('edition_id', 'conf_sub_reg_edition_fk')
                    ->references('id')->on('conf_editions')->cascadeOnDelete();
                $t->string('presentation_type', 16)->nullable();
                $t->string('participation_form', 16)->nullable();
                $t->string('presenter_email', 255)->nullable();
                $t->string('consent_version', 16)->nullable();
                $t->dateTime('consent_at')->nullable();
                $t->bigInteger('consent_user_id')->nullable();
                $t->dateTime('registered_at')->nullable();
                $t->timestamps();
                $t->unique('submission_id', 'conf_sub_reg_submission_unique');
                $t->index('edition_id', 'conf_sub_reg_edition');
            });
        }
        if (!$schema->hasColumn('conf_participants', 'confirmation_expires_at')) {
            $schema->table('conf_participants', function (Blueprint $t) {
                $t->dateTime('confirmation_expires_at')->nullable();
                $t->dateTime('confirmed_at')->nullable();
            });
        }
    }
}
