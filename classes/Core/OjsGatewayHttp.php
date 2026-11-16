<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Core;

use APP\core\Application;
use APP\plugins\generic\conferenceManager\classes\Domain\Payment\Gateway\GatewayHttp;

/** PAY-09: requests to a payment gateway through the HTTP client of OJS (its proxy settings apply). */
final class OjsGatewayHttp implements GatewayHttp
{
    public function postForm(string $url, array $form): ?string
    {
        try {
            $response = Application::get()->getHttpClient()->request('POST', $url, [
                'form_params' => $form,
                'timeout' => 20,
                'http_errors' => false,
            ]);
        } catch (\Throwable) {
            return null;
        }
        $code = $response->getStatusCode();
        return $code >= 200 && $code < 300 ? (string) $response->getBody() : null;
    }
}
