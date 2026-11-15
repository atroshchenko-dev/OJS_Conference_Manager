<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * A presenter under 18 and the consent of the legal representative in
 * the speaker questionnaire (EVT-04).
 */
final class Step0006MinorPresenters implements SchemaStep
{
    public function id(): string
    {
        return '0006_minor_presenters';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasColumn('conf_submission_registrations', 'presenter_minor')) {
            $schema->table('conf_submission_registrations', function (Blueprint $t) {
                $t->boolean('presenter_minor')->default(false);
                $t->dateTime('guardian_consent_at')->nullable();
            });
        }
    }
}
