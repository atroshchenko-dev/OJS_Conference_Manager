<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\SettingsStore;
use PKP\plugins\Plugin;

/** Plugin settings of the journal, stored as JSON objects. */
final class PluginSettingsStore implements SettingsStore
{
    public function __construct(private readonly ?Plugin $plugin)
    {
    }

    public function get(int $contextId, string $name): mixed
    {
        return $this->plugin?->getSetting($contextId, $name);
    }

    public function set(int $contextId, string $name, mixed $value): void
    {
        $type = match (true) {
            is_array($value) => 'object',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            default => 'string',
        };
        $this->plugin?->updateSetting($contextId, $name, $value, $type);
    }
}
