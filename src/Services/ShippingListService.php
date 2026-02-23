<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;

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

    public function submitDailyShipments(string $pickupDate = '', bool $returnList = true): array
    {
        if (empty($pickupDate)) {
            $pickupDate = date('Y-m-d');
        }

        $pendingShipments = $this->storageService->getPendingShipments($pickupDate);

        if (empty($pendingShipments)) {
            return [
                'result'              => 'no_pending',
                'list_id'             => '',
                'shipment_count'      => 0,
                'listPDF'             => null,
                'submitted_order_ids' => [],
            ];
        }

        $listId = 'LIST-' . date('Ymd') . '-' . time();

        $borderoPayload = $this->payloadBuilder->buildBorderoPayload(
            $pendingShipments,
            $pickupDate,
            $listId
        );

        $apiResponse = $this->apiService->submitShippingList($borderoPayload, $returnList);

        if (($apiResponse['result'] ?? '') !== 'ok') {
            throw new \RuntimeException(
                'Transland API returned unexpected result: ' . json_encode($apiResponse)
            );
        }

        $submittedOrderIds = array_column($pendingShipments, 'order_id');
        $this->storageService->markShipmentsAsSubmitted($submittedOrderIds, $listId);

        return [
            'result'              => 'ok',
            'list_id'             => $listId,
            'shipment_count'      => count($pendingShipments),
            'listPDF'             => $apiResponse['listPDF'] ?? null,
            'submitted_order_ids' => $submittedOrderIds,
        ];
    }

    public function getPendingShipments(string $date = ''): array
    {
        return $this->storageService->getPendingShipments($date ?: date('Y-m-d'));
    }

    public function storeShipmentAfterLabel(array $shipmentData): void
    {
        $this->storageService->storeShipment($shipmentData);
    }
}
