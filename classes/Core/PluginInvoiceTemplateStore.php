<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\plugins\generic\conferenceManager\classes\Application\InvoiceTemplateStore;
use PKP\plugins\Plugin;

/**
 * Context invoice templates as plugin settings "invoiceTemplate.{kind}".
 */
final class PluginInvoiceTemplateStore implements InvoiceTemplateStore
{
    public function __construct(private readonly ?Plugin $plugin)
    {
    }

    public function get(int $contextId, string $kind): ?string
    {
        $v = $this->plugin?->getSetting($contextId, 'invoiceTemplate.' . $kind);
        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    public function set(int $contextId, string $kind, ?string $html): void
    {
        $this->plugin?->updateSetting($contextId, 'invoiceTemplate.' . $kind, $html ?? '', 'string');
    }
}
