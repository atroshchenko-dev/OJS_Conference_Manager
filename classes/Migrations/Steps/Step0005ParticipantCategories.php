<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Participant category of the presenter in the speaker questionnaire
 * (REG-02, FEE-01).
 */
final class Step0005ParticipantCategories implements SchemaStep
{
    public function id(): string
    {
        return '0005_participant_categories';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasColumn('conf_submission_registrations', 'category_id')) {
            $schema->table('conf_submission_registrations', function (Blueprint $t) {
                $t->bigInteger('category_id')->unsigned()->nullable();
                $t->foreign('category_id', 'conf_sub_reg_category_fk')
                    ->references('id')->on('conf_fee_categories')->nullOnDelete();
            });
        }
    }
}
