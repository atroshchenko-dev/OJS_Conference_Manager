<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Minutes of presence from an online meeting report (ATT-02, EVT-05).
 * Attendance marks themselves live in conf_participations since 0003.
 */
final class Step0009Attendance implements SchemaStep
{
    public function id(): string
    {
        return '0009_attendance';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasColumn('conf_participations', 'attended_minutes')) {
            $schema->table('conf_participations', function (Blueprint $t) {
                $t->integer('attended_minutes')->nullable();
            });
        }
    }
}
