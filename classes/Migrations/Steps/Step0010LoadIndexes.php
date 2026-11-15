<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Migrations\Steps;

use APP\plugins\generic\conferenceManager\classes\Migrations\SchemaStep;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Load profiles (plan, section 15.2): the hourly letter limit counts sent
 * letters of all journals, and the quiet period looks for deadlines.
 */
final class Step0010LoadIndexes implements SchemaStep
{
    public function id(): string
    {
        return '0010_load_indexes';
    }

    public function up(Builder $schema, ConnectionInterface $db): void
    {
        if (!$schema->hasIndex('conf_campaign_recipients', 'conf_campaign_recipients_sent')) {
            $schema->table('conf_campaign_recipients', function (Blueprint $t) {
                $t->index(['status', 'sent_at'], 'conf_campaign_recipients_sent');
            });
        }
        if (!$schema->hasIndex('conf_editions', 'conf_editions_context_abstract')) {
            $schema->table('conf_editions', function (Blueprint $t) {
                $t->index(['context_id', 'abstract_deadline'], 'conf_editions_context_abstract');
                $t->index(['context_id', 'paper_deadline'], 'conf_editions_context_paper');
            });
        }
    }
}
