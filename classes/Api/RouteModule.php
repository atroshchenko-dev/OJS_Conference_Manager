<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Api;

/**
 * A group of routes of one module under the shared "conference" path.
 */
interface RouteModule
{
    /** Registers the routes with the Route facade (called inside the controller's group). */
    public function routes(): void;
}
