<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Webshop\Contracts\HttpLibraryContract;

/**
 * TranslandApiService
 *
 * HTTP client for the Zufall/Transland Shipping API.
 * Uses DigestAuth as required by the API.
 */
class TranslandApiService
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private HttpLibraryContract $httpClient;

    public function __construct(SettingsService $settingsService, HttpLibraryContract $httpClient)
    {
        $settings = $settingsService->getSettings();

        $sandbox          = ($settings['sandbox'] ?? '1') === '1';
        $customerId       = $settings['api_customer_id'] ?? '';
        $this->username   = $settings['username'] ?? '';
        $this->password   = $settings['password'] ?? '';
        $this->httpClient = $httpClient;

        $host = $sandbox ? 'test-edigate.zufall.de' : 'edigate.zufall.de';
        $this->baseUrl = 'https://' . $host . '/dw/request/shippingapi/' . $customerId;
    }

    /**
     * Label anfordern
     */
    public function requestLabel(array $payload, string $format = 'PDF'): array
    {
        $queryParam = strtoupper($format) === 'ZPL' ? '?format=ZPL' : '';
        $response = $this->doRequest('POST', '/label' . $queryParam, $payload);

        if (!$response['success']) {
            return ['packages' => [], 'label_data' => '', 'sscc_list' => []];
        }

        $data = $response['data'];
        $ssccList = array_filter(array_column($data['packages'] ?? [], 'sscc'));

        return [
            'packages'   => $data['packages']   ?? [],
            'label_data' => $data['label_data']  ?? '',
            'sscc_list'  => array_values($ssccList),
        ];
    }

    /**
     * Tagesabschluss (Bordero / Versandliste) senden
     */
    public function submitShippingList(array $payload, bool $returnList = true): array
    {
        $queryParam = $returnList ? '?returnList=true' : '';
        $response = $this->doRequest('POST', '/shipping-list' . $queryParam, $payload);

        if (!$response['success']) {
            return ['result' => 'error', 'listPDF' => null];
        }

        $data = $response['data'];

        return [
            'result'  => $data['result'] ?? $data['status'] ?? '',
            'SSCCs'   => $data['SSCCs']  ?? [],
            'listPDF' => $data['listPDF'] ?? null,
        ];
    }

    /**
     * Zentraler HTTP-Request via Plenty Library (Digest Auth)
     */
    private function doRequest(string $method, string $path, array $body): array
    {
        $url = $this->baseUrl . $path;

        try {
            $response = $this->httpClient->doRequest($url, $method, [
                'auth'    => [$this->username, $this->password, 'digest'],
                'json'    => $body,
                'headers' => [
                    'Accept' => 'application/json'
                ],
                'connect_timeout' => 15,
                'timeout'         => 60
            ]);

            $status = $response->getStatusCode();
            $content = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => ($status >= 200 && $status < 300),
                'data'    => $content
            ];
        } catch (\Exception $e) {
            return ['success' => false];
        }
    }
}