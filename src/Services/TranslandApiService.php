<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;

/**
 * TranslandApiService
 *
 * HTTP client for the Zufall/Transland Shipping API.
 * Uses DigestAuth as required by the API.
 *
 * Two endpoints:
 *   POST /label         → returns label PDF + SSCC (does NOT register shipment)
 *   POST /shipping-list → registers shipments as Bordero (Transportauftrag)
 */
class TranslandApiService
{
    use Loggable;

    private string $baseUrl;
    private string $username;
    private string $password;

    public function __construct(SettingsService $settingsService)
    {
        $settings = $settingsService->getSettings();

        $sandbox          = ($settings['sandbox'] ?? '1') === '1';
        $customerId       = $settings['api_customer_id'] ?? '';
        $this->username   = $settings['username'] ?? '';
        $this->password   = $settings['password'] ?? '';

        $host = $sandbox
            ? 'test-edigate.zufall.de'
            : 'edigate.zufall.de';

        // e.g. https://test-edigate.zufall.de/dw/request/shippingapi/venturama
        $this->baseUrl = 'https://' . $host . '/dw/request/shippingapi/' . $customerId;
    }

    // -------------------------------------------------------------------------
    // Label anfordern
    // Called by LabelService – does NOT register the shipment at Zufall.
    // Zufall assigns SSCC and returns the label PDF.
    //
    // @param array  $payload  Shipping object (single shipment)
    // @param string $format   'PDF' (default) or 'ZPL'
    //
    // @return array{
    //   packages: array,       packages with sscc filled in
    //   label_data: string,    base64 encoded label (PDF or ZPL)
    //   sscc_list: string[]
    // }
    // -------------------------------------------------------------------------
    public function requestLabel(array $payload, string $format = 'PDF'): array
    {
        $queryParam = strtoupper($format) === 'ZPL' ? '?format=ZPL' : '';
        $raw = $this->request('POST', '/label' . $queryParam, $payload);

        if (!($raw['success'] ?? false)) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::api.labelError', [
                'error' => $raw['error'] ?? json_encode($raw),
            ]);
            return ['packages' => [], 'label_data' => '', 'sscc_list' => []];
        }

        $data = $raw['data'];

        // Extract SSCCs from returned packages
        $ssccList = array_filter(array_column($data['packages'] ?? [], 'sscc'));

        return [
            'packages'   => $data['packages']   ?? [],
            'label_data' => $data['label_data']  ?? '',
            'sscc_list'  => array_values($ssccList),
        ];
    }

    // -------------------------------------------------------------------------
    // Tagesabschluss (Bordero / Versandliste) senden
    // Called by ShippingListService – THIS actually creates transport orders.
    //
    // @param array $payload     Versandliste object with shippings[]
    // @param bool  $returnList  If true, Zufall returns Ladeliste PDF
    //
    // @return array{
    //   result: string,        'ok' on success
    //   listPDF: string|null   base64 PDF if returnList=true
    // }
    // -------------------------------------------------------------------------
    public function submitShippingList(array $payload, bool $returnList = true): array
    {
        $queryParam = $returnList ? '?returnList=true' : '';
        $raw = $this->request('POST', '/shipping-list' . $queryParam, $payload);

        if (!($raw['success'] ?? false)) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::api.shippingListError', [
                'error' => $raw['error'] ?? json_encode($raw),
            ]);
            return ['result' => 'error', 'listPDF' => null];
        }

        $data = $raw['data'];

        return [
            // API returns either 'result' or 'status' field
            'result'  => $data['result'] ?? $data['status'] ?? '',
            'SSCCs'   => $data['SSCCs']  ?? [],
            'listPDF' => $data['listPDF'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal HTTP client with Digest Auth
    // -------------------------------------------------------------------------
    private function request(string $method, string $path, array $body): array
    {
        $url  = $this->baseUrl . $path;
        $json = ($method !== 'GET' && count($body) > 0) ? json_encode($body) : null;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,

            // DigestAuth only – no Basic fallback
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,

            CURLOPT_POSTFIELDS     => $json,

            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],

            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $this->getLogger(__CLASS__)->error('TranslandShipping::api.curlStart', [
            'url'    => $url,
            'method' => $method,
        ]);

        $responseBody = curl_exec($ch);
        $httpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        $curlErrNo    = curl_errno($ch);
        curl_close($ch);

        $this->getLogger(__CLASS__)->error('TranslandShipping::api.curlDone', [
            'url'        => $url,
            'httpStatus' => $httpStatus,
            'curlErrNo'  => $curlErrNo,
            'curlError'  => $curlError,
            'bodyLength' => $responseBody !== false ? strlen($responseBody) : 'FALSE',
        ]);

        if ($responseBody === false) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::api.curlError', [
                'url'   => $url,
                'error' => $curlError,
            ]);
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::api.httpError', [
                'url'    => $url,
                'status' => $httpStatus,
                'body'   => $responseBody,
            ]);
            return [
                'success' => false,
                'status'  => $httpStatus,
                'error'   => 'HTTP ' . $httpStatus,
                'body'    => $responseBody,
            ];
        }

        $decoded      = json_decode($responseBody, true);
        $jsonLastError = json_last_error();

        $this->getLogger(__CLASS__)->error('TranslandShipping::api.response', [
            'url'          => $url,
            'status'       => $httpStatus,
            'jsonError'    => $jsonLastError,
            'decodedKeys'  => is_array($decoded) ? array_keys($decoded) : 'NOT_ARRAY',
            'result_field' => $decoded['result'] ?? 'MISSING',
            'has_listPDF'  => isset($decoded['listPDF']) ? 'JA (' . strlen($decoded['listPDF']) . ' chars)' : 'NEIN',
        ]);

        return [
            'success' => true,
            'status'  => $httpStatus,
            'data'    => $decoded ?? $responseBody,
        ];
    }
}