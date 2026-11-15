<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Application\ContextInfo;
use PKP\context\Context;

/**
 * Adapter to OJS contexts. The only place that knows how OJS stores
 * context names and locales (plan 7.2, rule 2).
 */
final class ContextGateway
{
    public function info(int $contextId): ?ContextInfo
    {
        $context = Application::getContextDAO()->getById($contextId);
        return $context instanceof Context ? self::fromContext($context) : null;
    }

    /**
     * Absolute URL of a public page of the plugin in a context, such as
     * /{context}/conference/verify/{uuid}. Empty when the context is gone.
     *
     * @param list<string> $args
     */
    public static function pageUrl(int $contextId, string $op, array $args = []): string
    {
        $context = Application::getContextDAO()->getById($contextId);
        $request = Application::get()->getRequest();
        if (!$context instanceof Context) {
            return '';
        }
        return (string) $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'conference', $op, $args);
    }

    public static function fromContext(Context $context): ContextInfo
    {
        $primary = (string) $context->getPrimaryLocale();
        $formLocales = array_values((array) ($context->getSupportedFormLocales() ?? [$primary]));
        if (!in_array($primary, $formLocales, true)) {
            array_unshift($formLocales, $primary);
        }
        $name = array_filter((array) $context->getName(null), 'is_string');

        return new ContextInfo(
            (int) $context->getId(),
            $primary,
            $formLocales,
            $name,
            (string) $context->getData('contactEmail'),
            date_default_timezone_get(),
        );
    }
}
