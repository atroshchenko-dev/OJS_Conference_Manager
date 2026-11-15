<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Small per-journal settings of the plugin (export columns, reminders):
 * plugin settings in OJS, an array in tests.
 */
interface SettingsStore
{
    public function get(int $contextId, string $name): mixed;

    public function set(int $contextId, string $name, mixed $value): void;
}
