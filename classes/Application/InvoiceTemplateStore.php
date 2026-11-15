<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Application;

/**
 * Invoice templates the context changed (PAY-07). Null: the built-in one.
 */
interface InvoiceTemplateStore
{
    public function get(int $contextId, string $kind): ?string;

    public function set(int $contextId, string $kind, ?string $html): void;
}
