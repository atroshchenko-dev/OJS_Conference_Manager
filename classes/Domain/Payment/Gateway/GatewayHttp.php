<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway;

/** PAY-09: the only way a gateway talks to its service. */
interface GatewayHttp
{
    /**
     * Posts a form; returns the body, or null on a network error or a non-2xx answer.
     *
     * @param array<string, string> $form
     */
    public function postForm(string $url, array $form): ?string;
}
