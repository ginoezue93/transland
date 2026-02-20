<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;

/**
 * ShippingListService
 *
 * Handles the daily Bordero/Versandliste submission to Transland.
 * Collects all pending shipments (label printed, not yet submitted),
 * builds a Bordero payload and submits it to the Transland shipping-list endpoint.
 *
 * This is the step that actually creates transport orders at Transland.
 */
class ShippingListService
{
    use Loggable;

    private TranslandApiService    $apiService;
    private PayloadBuilderService  $payloadBuilder;
    private StorageService         $storageService;

    public function __construct(
        TranslandApiService   $apiService,
        PayloadBuilderService $payloadBuilder,
        StorageService        $storageService
    ) {
        $this->apiService     = $apiService;
        $this->payloadBuilder = $payloadBuilder;
        $this->storageService = $storageService;
    }

    /**
     * Submit all pending shipments for a given pickup date to Transland.
     * "Pending" means: label was printed but shipment not yet reported in Bordero.
     *
     * @param string $pickupDate  Format: YYYY-MM-DD (defaults to today)
     * @param bool   $returnList  If true, Transland returns a base64 PDF Ladeliste
     *
     * @return array{
     *   result: string,
     *   list_id: string,
     *   shipment_count: int,
     *   listPDF: string|null,
     *   submitted_order_ids: int[]
     * }
     * @throws \RuntimeException if no pending shipments or API call fails
     */
    public function submitDailyShipments(string $pickupDate = '', bool $returnList = true): array
    {
        if (empty($pickupDate)) {
            $pickupDate = date('Y-m-d');
        }

        // Load all shipments that have been label-printed but not yet submitted
        $pendingShipments = $this->storageService->getPendingShipments($pickupDate);

        if (empty($pendingShipments)) {
            $this->getLogger(__METHOD__)->info('TranslandShipping::bordero.noPending', [
                'pickupDate' => $pickupDate,
            ]);
            return [
                'result'               => 'no_pending',
                'list_id'              => '',
                'shipment_count'       => 0,
                'listPDF'              => null,
                'submitted_order_ids'  => [],
            ];
        }

        // Generate a unique list ID: date + timestamp
        $listId = 'LIST-' . date('Ymd') . '-' . time();

        // Build Bordero payload
        $borderoPayload = $this->payloadBuilder->buildBorderoPayload(
            $pendingShipments,
            $pickupDate,
            $listId
        );

        $this->getLogger(__METHOD__)->info('TranslandShipping::bordero.submitting', [
            'listId'        => $listId,
            'pickupDate'    => $pickupDate,
            'shipmentCount' => count($pendingShipments),
        ]);

        // Submit to Transland
        $apiResponse = $this->apiService->submitShippingList($borderoPayload, $returnList);

        if (($apiResponse['result'] ?? '') !== 'ok') {
            throw new \RuntimeException(
                'Transland API returned unexpected result: ' . json_encode($apiResponse)
            );
        }

        // Mark all submitted shipments as done in storage
        $submittedOrderIds = array_column($pendingShipments, 'order_id');
        $this->storageService->markShipmentsAsSubmitted($submittedOrderIds, $listId);

        $this->getLogger(__METHOD__)->info('TranslandShipping::bordero.success', [
            'listId'              => $listId,
            'submittedOrderIds'   => $submittedOrderIds,
        ]);

        return [
            'result'              => 'ok',
            'list_id'             => $listId,
            'shipment_count'      => count($pendingShipments),
            'listPDF'             => $apiResponse['listPDF'] ?? null,
            'submitted_order_ids' => $submittedOrderIds,
        ];
    }

    /**
     * Get all shipments currently pending (label printed, not yet in a Bordero).
     */
    public function getPendingShipments(string $date = ''): array
    {
        return $this->storageService->getPendingShipments($date ?: date('Y-m-d'));
    }

    /**
     * Store shipment data after label has been printed.
     * Called by LabelController after a successful label request.
     */
    public function storeShipmentAfterLabel(array $shipmentData): void
    {
        $this->storageService->storeShipment($shipmentData);
    }
}
