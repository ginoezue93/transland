<?php

namespace Transland\Services;

use Plenty\Plugin\Log\Loggable;

class TranslandApiService
{
    use Loggable;

    private string $baseUrl;
    private string $username;
    private string $password;
    private string $customerId;

    public function __construct(SettingsService $settingsService)
    {
        $settings = $settingsService->getSettings();

        $sandbox        = $settings['sandbox'] ?? true;
        $this->customerId = $settings['customer_id'] ?? '';
        $this->username = $settings['api_username'] ?? '';
        $this->password = $settings['api_password'] ?? '';

        $host = $sandbox
            ? 'test-edigate.zufall.de'
            : 'edigate.zufall.de';

        $this->baseUrl = 'https://' . $host . '/dw/request/shippingapi/' . $this->customerId;
    }

    // -------------------------------------------------------------------------
    // Label anfordern
    // -------------------------------------------------------------------------
    public function createLabel(array $payload): array
    {
        return $this->request('POST', '/label', $payload);
    }

    // -------------------------------------------------------------------------
    // Tagesabschluss (Bordero) senden
    // -------------------------------------------------------------------------
    public function submitBordero(array $payload): array
    {
        return $this->request('POST', '/bordero', $payload);
    }

    // -------------------------------------------------------------------------
    // Sendungsstatus abfragen
    // -------------------------------------------------------------------------
    public function getStatus(string $sscc): array
    {
        return $this->request('GET', '/status/' . $sscc, []);
    }

    // -------------------------------------------------------------------------
    // Interner HTTP-Client mit Digest Auth
    // -------------------------------------------------------------------------
    private function request(string $method, string $path, array $body): array
    {
        $url  = $this->baseUrl . $path;
        $json = ($method !== 'GET' && count($body) > 0) ? json_encode($body) : null;

        $ch = curl_init();

        curl_setopt_array($ch, [
            // URL & Methode
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,

            // Digest Auth – NUR DIGEST, kein Basic-Fallback
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,

            // Request Body
            CURLOPT_POSTFIELDS     => $json,

            // Header
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],

            // Response
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,

            // TLS
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            // Timeout
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,

            // Digest braucht zwei Roundtrips – cURL macht das automatisch
            // (1. Request ohne Auth → 401 + nonce → 2. Request mit Hash)
            // CURLOPT_FOLLOWLOCATION ist NICHT nötig, cURL handelt das intern
        ]);

        $responseBody = curl_exec($ch);
        $httpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        // cURL-Fehler (Netzwerk, TLS, Timeout)
        if ($responseBody === false) {
            $this->getLogger(__CLASS__)->error('Transland cURL error', [
                'url'   => $url,
                'error' => $curlError,
            ]);
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        // HTTP-Fehler
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $this->getLogger(__CLASS__)->error('Transland HTTP error', [
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

        // Erfolg
        $decoded = json_decode($responseBody, true);
        return [
            'success' => true,
            'status'  => $httpStatus,
            'data'    => $decoded ?? $responseBody,
        ];
    }
}