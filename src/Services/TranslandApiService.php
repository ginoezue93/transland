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

        // e.g. https://test-edigate.zufall.de/dw/request/shippingapi/venturama/V2
        $this->baseUrl = 'https://' . $host . '/dw/request/shippingapi/' . $customerId . '/V2';
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

        // Diagnostic dump of the label response so we can verify which format
        // Zufall's endpoint actually uses (v1.x = "packages", v2.0 = "positions")
        // and whether label_data is really empty or gets lost in our parsing.
        $labelData = is_array($data) ? ($data['label_data'] ?? '') : '';
        $this->getLogger(__CLASS__)->error('TranslandShipping::api.labelResponseDump', [
            'top_level_keys'   => is_array($data) ? array_keys($data) : 'NOT_ARRAY',
            'has_positions'    => isset($data['positions']) ? 'yes' : 'no',
            'has_packages'     => isset($data['packages']) ? 'yes' : 'no',
            'positions_count'  => isset($data['positions']) && is_array($data['positions']) ? count($data['positions']) : 0,
            'packages_count'   => isset($data['packages']) && is_array($data['packages']) ? count($data['packages']) : 0,
            'label_data_len'   => is_string($labelData) ? strlen($labelData) : 0,
            'label_data_start' => is_string($labelData) && strlen($labelData) > 0 ? substr($labelData, 0, 40) : '<empty>',
            'raw_body_snippet' => is_array($data) ? substr(json_encode($data), 0, 1500) : '<not-an-array>',
        ]);

        // Extract positions/packages – try v2.0 spec first (positions), fall
        // back to v1.x (packages). Both are supported because Zufall's
        // endpoints may transition over time.
        $positionsArray = [];
        if (isset($data['positions']) && is_array($data['positions'])) {
            $positionsArray = $data['positions'];
        } elseif (isset($data['packages']) && is_array($data['packages'])) {
            $positionsArray = $data['packages'];
        }

        // Extract SSCCs from the position array.
        // V2 actual response: position.ssccs[] (array of strings)
        // V2 doc spec: position.packages[].sscc
        // V1: position.sscc (flat string)
        // We support all three for maximum compatibility.
        $ssccList = [];
        foreach ($positionsArray as $pos) {
            if (!is_array($pos)) {
                continue;
            }
            // V2 actual: position.ssccs[] (array of SSCC strings)
            if (isset($pos['ssccs']) && is_array($pos['ssccs'])) {
                foreach ($pos['ssccs'] as $sscc) {
                    if (!empty($sscc)) {
                        $ssccList[] = (string) $sscc;
                    }
                }
            }
            // V2 doc: position.packages[].sscc
            if (isset($pos['packages']) && is_array($pos['packages'])) {
                foreach ($pos['packages'] as $pkg) {
                    if (is_array($pkg) && !empty($pkg['sscc'])) {
                        $ssccList[] = (string) $pkg['sscc'];
                    }
                }
            }
            // V1: position.sscc (flat)
            if (!empty($pos['sscc'])) {
                $ssccList[] = (string) $pos['sscc'];
            }
        }
        $ssccList = array_values(array_unique($ssccList));

        return [
            'packages'   => $positionsArray,
            'label_data' => $labelData,
            'sscc_list'  => $ssccList,
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

        $decoded = json_decode($responseBody, true);

        $this->getLogger(__CLASS__)->error('TranslandShipping::api.response', [
            'url'          => $url,
            'status'       => $httpStatus,
            'decodedKeys'  => is_array($decoded) ? array_keys($decoded) : 'NOT_ARRAY',
            'result_field' => $decoded['result'] ?? $decoded['status'] ?? 'MISSING',
            'has_listPDF'  => isset($decoded['listPDF']) ? 'JA' : 'NEIN',
        ]);

        return [
            'success' => true,
            'status'  => $httpStatus,
            'data'    => $decoded ?? $responseBody,
        ];
    }
}