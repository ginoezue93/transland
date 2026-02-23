<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;

/**
 * LabelService
 *
 * Orchestrates the label-printing step during the packing process.
 * This does NOT register the shipment at Transland.
 * The SSCC returned by Transland is stored as a property on the Plenty package.
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
     * @param string $format   'PDF' or 'ZPL'
     * @param array  $options  Optional Sendungsoptionen (codes)
     *
     * @return array{
     *   label_data: string,
     *   label_format: string,
     *   packages: array,
     *   order_id: int,
     *   sscc_list: string[],
     *   shipment_data: array
     * }
     */
    public function createLabelForOrder(
        array $order,
        array $packages,
        string $format = 'PDF',
        array $options = []
    ): array {
        $orderId = $order['id'];

        $this->getLogger(__METHOD__)->error('TranslandShipping::label.start', [
            'orderId'      => $orderId,
            'packageCount' => count($packages),
        ]);

        $payload = $this->payloadBuilder->buildLabelPayload($order, $packages, $options);

        $result = $this->apiService->requestLabel($payload, $format);

        $packagesWithSscc = $this->mergeSSCCsIntoPackages($packages, $result['packages']);

        $settings = $this->settingsService->getSettings();

        $pickupDate = date('Y-m-d');

        $fullShipmentData = [
            'order_id'          => $orderId,
            'pickup_date'       => $pickupDate,

            // Adressen – direkt als fertige Arrays
            'shipper_address'   => $this->payloadBuilder->buildShipperAddress($settings),
            'consignee_address' => $this->payloadBuilder->buildConsigneeAddress($order),

            // loading_address = shipper_address (Abholort = Lager)
            'loading_address'   => $this->payloadBuilder->buildShipperAddress($settings),

            'procurement'       => false,
            'franking'          => '1',

            // Referenz: nur die Auftragsnummer (externe ID bevorzugt, sonst interne ID)
            'reference'         => !empty($order['externalOrderId'])
                ? $order['externalOrderId']
                : (string)$orderId,

            'value'             => (string)round(($payload['value'] ?? 0) / 100, 2),
            'value_currency'    => $payload['value_currency'] ?? 'EUR',

            'weight_gr'         => (int)($payload['weight_gr'] ?? 0),

            'options'           => !empty($options) ? $options : $this->payloadBuilder->buildDefaultOptions($order),

            'packages'          => $this->payloadBuilder->buildPackages($packagesWithSscc),

            'texts'             => $payload['texts'] ?? [],
            'label_printed_at'  => date('Y-m-d H:i:s'),
        ];

        $this->getLogger(__METHOD__)->error('TranslandShipping::label.success', [
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