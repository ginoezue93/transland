<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Log\Loggable;

/**
 * TranslandApiService
 *
 * Handles all HTTP communication with the Zufall/Transland REST API.
 * Authentication: HTTP Digest Auth
 * Base URLs:
 *   Test:       https://test-edigate.zufall.de/dw/request/shippingapi/{KUNDE}/
 *   Production: https://edigate.zufall.de/dw/request/shippingapi/{KUNDE}/
 */
class TranslandApiService
{
    use Loggable;

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    // -------------------------------------------------------------------------
    // Label endpoint
    // -------------------------------------------------------------------------

    /**
     * Request a shipping label (PDF or ZPL) from Transland.
     * NOTE: This does NOT register the shipment. Use submitShippingList for that.
     *
     * @param array  $shippingPayload  A single Shipping object (see API docs)
     * @param string $format           'PDF' (default) or 'ZPL'
     *
     * @return array{
     *   packages: array,
     *   label_data: string,
     *   sscc_list: string[]
     * }
     */
    public function requestLabel(array $shippingPayload, string $format = 'PDF'): array
    {
        $queryParams = [];
        if (strtoupper($format) === 'ZPL') {
            $queryParams['format'] = 'ZPL';
        }

        $response = $this->post('label', $shippingPayload, $queryParams);

        // Extract all SSCCs so the caller can persist them in Plenty
        $ssccList = array_map(
            fn(array $pkg) => $pkg['sscc'] ?? '',
            $response['packages'] ?? []
        );

        return [
            'packages'   => $response['packages'] ?? [],
            'label_data' => $response['label_data'] ?? '',
            'sscc_list'  => array_filter($ssccList),
        ];
    }

    // -------------------------------------------------------------------------
    // Shipping-list endpoint
    // -------------------------------------------------------------------------

    /**
     * Submit a complete Bordero/Versandliste to Transland.
     * This creates actual transport orders in their system.
     *
     * @param array $borderoPayload  A Versandliste object (see API docs)
     * @param bool  $returnList      If true, response contains a base64 PDF Ladeliste
     *
     * @return array{result: string, listPDF?: string}
     */
    public function submitShippingList(array $borderoPayload, bool $returnList = false): array
    {
        $queryParams = $returnList ? ['returnList' => 'true'] : [];
        return $this->post('shipping-list', $borderoPayload, $queryParams);
    }

    // -------------------------------------------------------------------------
    // Internal HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Perform a POST request against the Transland API.
     *
     * @param string $endpoint    'label' or 'shipping-list'
     * @param array  $body        JSON body payload
     * @param array  $queryParams Optional query parameters
     *
     * @return array Decoded JSON response
     * @throws \RuntimeException on HTTP or API error
     */
    private function post(string $endpoint, array $body, array $queryParams = []): array
    {
        $settings = $this->settingsService->getSettings();
        $baseUrl  = $this->buildBaseUrl($settings);
        $url      = $baseUrl . $endpoint;

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $this->getLogger(__METHOD__)->debug(
            'TranslandShipping::api.request',
            ['url' => $url, 'body' => $body]
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            // Digest Authentication (as specified by Transland)
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $settings['username'] . ':' . $settings['password'],
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::api.curlError', ['error' => $curlError]);
            throw new \RuntimeException('cURL error: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);

        if ($httpCode !== 200) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::api.httpError', [
                'httpCode' => $httpCode,
                'response' => $rawResponse,
            ]);
            throw new \RuntimeException(
                sprintf('Transland API error (HTTP %d): %s', $httpCode, $rawResponse)
            );
        }

        $this->getLogger(__METHOD__)->debug('TranslandShipping::api.response', ['response' => $decoded]);

        return $decoded ?? [];
    }

    /**
     * Build the base URL from settings.
     * Pattern: https://{host}/dw/request/shippingapi/{KUNDE}/
     */
    private function buildBaseUrl(array $settings): string
    {
        $useSandbox = (bool)($settings['sandbox'] ?? true);
        $host       = $useSandbox
            ? 'test-edigate.zufall.de'
            : 'edigate.zufall.de';
        $kunde      = $settings['api_customer_id'] ?? '';

        return sprintf('https://%s/dw/request/shippingapi/%s/', $host, $kunde);
    }
}
