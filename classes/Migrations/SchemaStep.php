<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;

/**
 * One schema change. Every step MUST be idempotent: it checks
 * hasTable / hasColumn / hasIndex before changing anything, because OJS
 * re-runs plugin migrations whenever any plugin is installed or OJS is
 * upgraded (plan 7.3).
 */
interface SchemaStep
{
    /** Stable id, e.g. "0001_institution". Never rename a released step. */
    public function id(): string;

    public function up(Builder $schema, ConnectionInterface $db): void;
}
