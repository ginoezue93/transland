<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Models\OrderShippingPackage;

/**
 * LabelService
 *
 * Orchestrates the label-printing step during the packing process.
 * This does NOT register the shipment at Transland.
 * The SSCC returned by Transland is stored as a property on the Plenty package.
 *
 * Called from packing processes: 52, 73, 79, 85, 87
 */
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

    /**
     * Main entry point: create label for a PlentyMarkets order during packing.
     *
     * @param array  $order    PlentyMarkets order data
     * @param array  $packages Package data entered during packing process
     *                         Each package: [content, packaging_type, length_cm, width_cm, height_cm, weight_gr]
     * @param string $format   'PDF' or 'ZPL'
     * @param array  $options  Optional Sendungsoptionen (codes)
     *
     * @return array{
     *   label_data: string,   base64 encoded label (PDF or ZPL)
     *   label_format: string, PDF or ZPL
     *   packages: array,      packages with SSCC filled in
     *   order_id: int,
     *   sscc_list: string[],  all SSCCs for this shipment
     *   shipment_data: array  full data snapshot to store in Plenty for later Bordero submission
     * }
     */
    public function createLabelForOrder(
        array $order,
        array $packages,
        string $format = 'PDF',
        array $options = []
    ): array {
        $orderId = $order['id'];

        $this->getLogger(__METHOD__)->info('TranslandShipping::label.start', [
            'orderId'      => $orderId,
            'packageCount' => count($packages),
        ]);

        // Build the API payload
        $payload = $this->payloadBuilder->buildLabelPayload($order, $packages, $options);

        // Call Transland label API
        $result = $this->apiService->requestLabel($payload, $format);

        // Merge SSCCs back into package data
        $packagesWithSscc = $this->mergeSSCCsIntoPackages($packages, $result['packages']);

        // Build a full data snapshot for later use in the Bordero
        $settings         = $this->settingsService->getSettings();
        $fullShipmentData = [
            'order_id'          => $orderId,
            'shipper_address'   => $this->payloadBuilder->buildShipperAddress($settings),
            'consignee_address' => $this->payloadBuilder->buildConsigneeAddress($order),
            'franking'          => '1',
            'reference'         => implode(' / ', array_filter([
                'ORD-' . $orderId,
                $order['externalOrderId'] ?? null,
            ])),
            'value'             => (int)($payload['value'] ?? 0),
            'value_currency'    => $payload['value_currency'] ?? 'EUR',
            'weight_gr'         => (int)($payload['weight_gr'] ?? 0),
            'options'           => $options,
            'packages'          => $this->payloadBuilder->buildPackages($packagesWithSscc),
            'texts'             => $payload['texts'] ?? [],
            'label_printed_at'  => date('Y-m-d H:i:s'),
            'submitted_to_api'  => false, // will be set to true after Bordero submission
        ];

        $this->getLogger(__METHOD__)->info('TranslandShipping::label.success', [
            'orderId'  => $orderId,
            'ssccList' => $result['sscc_list'],
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

    /**
     * Merge SSCCs returned by Transland back into the local package objects.
     */
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
