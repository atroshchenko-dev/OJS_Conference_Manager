<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * PRG-01: the program page of an edition is public once the journal
 * publishes it (its introduction text lives in conf_edition_settings).
 */
final class Step0011Program implements SchemaStep
{
    public function id(): string
    {
        return '0011_program';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasColumn('conf_editions', 'program_published')) {
            $schema->table('conf_editions', function (Blueprint $t) {
                $t->smallInteger('program_published')->default(0);
            });
        }
    }
}
