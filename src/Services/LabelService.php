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
        array $options = []
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

        // Options: Standard-Options wenn keine übergeben
        $finalOptions = !empty($options)
            ? $options
            : $this->payloadBuilder->buildDefaultOptions($order);

        $fullShipmentData = [
            'order_id'          => $orderId,
            'pickup_date'       => \TranslandShipping\Services\StorageService::calcPickupDate(),
            'shipper_address'   => $shipperAddress,
            'consignee_address' => $consigneeAddress,
            'loading_address'   => $shipperAddress,
            'procurement'       => false,
            'franking'          => '1',
            'reference'         => $reference,
            'value'             => (string)round(($payload['value'] ?? 0) / 100, 2),
            'value_currency'    => $payload['value_currency'] ?? 'EUR',
            'weight_gr'         => (int)($payload['weight_gr'] ?? 0),
            'options'           => $finalOptions,
            'packages'          => $this->payloadBuilder->buildPackages($packagesWithSscc),
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
        foreach ($localPackages as $idx => &$pkg) {
            if (isset($apiPackages[$idx]['sscc'])) {
                $pkg['sscc'] = $apiPackages[$idx]['sscc'];
            }
        }
        return $localPackages;
    }
}