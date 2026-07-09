<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;

class LabelService
{
    use Loggable;

    private TranslandApiService   $apiService;
    private PayloadBuilderService $payloadBuilder;
    private SettingsService       $settingsService;

    public function __construct(
        TranslandApiService   $apiService,
        PayloadBuilderService $payloadBuilder,
        SettingsService       $settingsService
    ) {
        $this->apiService      = $apiService;
        $this->payloadBuilder  = $payloadBuilder;
        $this->settingsService = $settingsService;
    }

    public function createLabelForOrder(
        array $order,
        array $packages,
        string $format = 'PDF',
        array $options = [],
        string $fixedPickupDate = ''
    ): array {
        $orderId  = $order['id'];
        $settings = $this->settingsService->getSettings();

        // Adressen VOR dem API-Call aufbauen
        $shipperAddress   = $this->payloadBuilder->buildShipperAddress($settings);
        $consigneeAddress = $this->payloadBuilder->buildConsigneeAddress($order);

        $this->getLogger(__METHOD__)->error('TranslandShipping::label.start', [
            'orderId'          => $orderId,
            'packageCount'     => count($packages),
            'shipper_name1'    => $shipperAddress['name1']    ?? 'LEER',
            'consignee_name1'  => $consigneeAddress['name1']  ?? 'LEER',
        ]);

        $payload = $this->payloadBuilder->buildLabelPayload($order, $packages, $options);
        $result  = $this->apiService->requestLabel($payload, $format);

        $packagesWithSscc = $this->mergeSSCCsIntoPackages($packages, $result['packages']);

        // Referenz: externe Auftragsnummer bevorzugt, sonst interne ID
        $reference = !empty($order['externalOrderId'])
            ? (string)$order['externalOrderId']
            : (string)$orderId;

        // Options: Default-Options (Avisierung 101, Referenz 502) IMMER dazu,
        // plus tag-basierte Options (NextDay etc.) wenn vorhanden.
        $defaultOptions = $this->payloadBuilder->buildDefaultOptions($order);
        $finalOptions = array_merge($defaultOptions, $options);

        $fullShipmentData = [
            'order_id'          => $orderId,
            'pickup_date'       => !empty($fixedPickupDate) ? $fixedPickupDate : \TranslandShipping\Services\StorageService::calcPickupDate(),
            'shipper_address'   => $shipperAddress,
            'consignee_address' => $consigneeAddress,
            'loading_address'   => $shipperAddress,
            'procurement'       => false,
            'franking'          => '1',
            'reference'         => $reference,
            'value'             => (string)($payload['shipping_value'] ?? 0),
            'value_currency'    => $payload['shipping_currency'] ?? 'EUR',
            'weight_gr'         => (int)($payload['weight_gr'] ?? 0),
            'options'           => $finalOptions,
            'packages'          => $this->payloadBuilder->buildPositions($packagesWithSscc, $order),
            'texts'             => $payload['texts'] ?? [],
            'label_printed_at'  => date('Y-m-d H:i:s'),
        ];

        $this->getLogger(__METHOD__)->error('TranslandShipping::label.success', [
            'orderId'                => $orderId,
            'ssccList'               => $result['sscc_list'],
            'stored_shipper_name1'   => $fullShipmentData['shipper_address']['name1']   ?? 'LEER',
            'stored_consignee_name1' => $fullShipmentData['consignee_address']['name1'] ?? 'LEER',
            'stored_reference'       => $fullShipmentData['reference'],
            'stored_package_count'   => count($fullShipmentData['packages']),
            'stored_value'           => $fullShipmentData['value'],
        ]);

        return [
            'label_data'    => $result['label_data'],
            'label_format'  => $format,
            'packages'      => $packagesWithSscc,
            'order_id'      => $orderId,
            'sscc_list'     => $result['sscc_list'],
            'shipment_data' => $fullShipmentData,
        ];
    }

    private function mergeSSCCsIntoPackages(array $localPackages, array $apiPackages): array
    {
        // Alle SSCCs aus ALLEN API-Positionen sammeln.
        $allSsccs = [];
        foreach ($apiPackages as $apiPos) {
            if (!is_array($apiPos)) {
                continue;
            }
            if (isset($apiPos['ssccs']) && is_array($apiPos['ssccs'])) {
                foreach ($apiPos['ssccs'] as $s) {
                    if (!empty($s)) {
                        $allSsccs[] = (string)$s;
                    }
                }
            }
            if (isset($apiPos['packages']) && is_array($apiPos['packages'])) {
                foreach ($apiPos['packages'] as $pkg) {
                    if (is_array($pkg) && !empty($pkg['sscc'])) {
                        $allSsccs[] = (string)$pkg['sscc'];
                    }
                }
            }
            if (!empty($apiPos['sscc'])) {
                $allSsccs[] = (string)$apiPos['sscc'];
            }
        }
        $allSsccs = array_values(array_unique($allSsccs));

        // Eine Sendung = eine SSCC für ALLE Positionen.
        // Wenn Zufall nur 1 SSCC zurückgibt (normal), bekommen alle
        // Positionen dieselbe SSCC. Bei mehreren SSCCs verteilen nach Index.
        $primarySscc = $allSsccs[0] ?? '';

        foreach ($localPackages as $idx => &$pkg) {
            $sscc = $allSsccs[$idx] ?? $primarySscc;
            if (!empty($sscc)) {
                $pkg['sscc'] = $sscc;
                $pkg['ssccs'] = [$sscc];
            }
        }

        return $localPackages;
    }
}