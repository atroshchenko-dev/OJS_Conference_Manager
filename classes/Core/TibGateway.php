<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\facades\Repo;
use APP\plugins\generic\conferenceManager\classes\Application\TibIssueFields;
use APP\plugins\generic\conferenceManager\classes\Domain\Edition\TibFields;
use PKP\plugins\LazyLoadPlugin;
use PKP\plugins\PluginRegistry;

/**
 * EDN-10: TIB Conference (plugins/generic/conference, class
 * ConferencePlugin) is optional. Its issue fields are read and written by
 * name through the issue repository; no class of TIB is called.
 */
final class TibGateway implements TibIssueFields
{
    /** TIB's plugin name: the lowercased class name. */
    public const PLUGIN = 'conferenceplugin';

    public function enabled(int $contextId): bool
    {
        $plugin = PluginRegistry::getPlugin('generic', self::PLUGIN);
        if (!$plugin instanceof LazyLoadPlugin || !$plugin->getEnabled($contextId)) {
            return false;
        }
        $schema = app()->get('schema')->get('issue');
        return isset($schema->properties->{TibFields::BEGIN});
    }

    public function write(int $contextId, int $issueId, array $fields): void
    {
        $issue = Repo::issue()->get($issueId, $contextId);
        if ($issue === null) {
            return;
        }
        $changed = [];
        foreach ($fields as $name => $value) {
            if ((string) $issue->getData($name) !== $value) {
                $changed[$name] = $value;
            }
        }
        if ($changed === []) {
            return;
        }
        Repo::issue()->edit($issue, $changed);
    }
}
