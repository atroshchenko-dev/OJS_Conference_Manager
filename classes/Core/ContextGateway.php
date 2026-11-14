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
        );
    }
}
