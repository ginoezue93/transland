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

    /**
     * Alle ausstehenden Sendungen als Bordero einreichen.
     *
     * WICHTIG: Wenn kein $pickupDate übergeben wird, werden ALLE pending Shipments
     * unabhängig vom Datum eingereicht (sinnvoll wenn Labels an Vortagen gedruckt wurden).
     */
    public function submitDailyShipments(string $pickupDate = '', bool $returnList = true): array
    {
        // Alle pending Shipments holen
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

        // pickup_date für den Bordero: entweder übergeben oder heute
        $borderoDate = !empty($pickupDate) ? $pickupDate : date('Y-m-d');

        // Sendungen nach pickup_date gruppieren → separate Bordero-Aufrufe pro Tag
        $grouped = [];
        foreach ($pendingShipments as $shipment) {
            $date = $shipment['pickup_date'] ?? $borderoDate;
            $grouped[$date][] = $shipment;
        }

        $allSubmittedOrderIds = [];
        $lastListId           = '';
        $lastListPDF          = null;
        $totalCount           = 0;

        foreach ($grouped as $date => $shipments) {
            $listId = 'LIST-' . str_replace('-', '', $date) . '-' . time();

            $borderoPayload = $this->payloadBuilder->buildBorderoPayload(
                $shipments,
                $date,
                $listId
            );

            // FULL PAYLOAD LOG – zeigt exakt was an die API gesendet wird
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.sending', [
                'pickup_date'    => $date,
                'shipment_count' => count($shipments),
                'list_id'        => $listId,
                'raw_shipments_from_db' => $shipments,
                'payload_shippings'     => $borderoPayload['shippings'] ?? [],
                'first_shipping_keys'   => !empty($borderoPayload['shippings'])
                    ? array_keys($borderoPayload['shippings'][0])
                    : [],
                'first_shipper_address' => $borderoPayload['shippings'][0]['shipper_address'] ?? 'MISSING',
                'full_payload_json'     => json_encode($borderoPayload),
            ]);

            $apiResponse = $this->apiService->submitShippingList($borderoPayload, $returnList);

            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.response', [
                'list_id'     => $listId,
                'api_result'  => $apiResponse['result']  ?? 'MISSING',
                'has_listPDF' => !empty($apiResponse['listPDF']) ? 'JA' : 'NEIN',
                'full_response' => json_encode($apiResponse),
            ]);

            if (($apiResponse['result'] ?? '') !== 'ok') {
                throw new \RuntimeException(
                    'Transland API returned unexpected result: ' . json_encode($apiResponse)
                );
            }

            $submittedOrderIds = array_column($shipments, 'order_id');
            $this->storageService->markShipmentsAsSubmitted($submittedOrderIds, $listId);

            $allSubmittedOrderIds = array_merge($allSubmittedOrderIds, $submittedOrderIds);
            $lastListId           = $listId;
            $lastListPDF          = $apiResponse['listPDF'] ?? null;
            $totalCount          += count($shipments);
        }

        return [
            'result'              => 'ok',
            'list_id'             => $lastListId,
            'shipment_count'      => $totalCount,
            'listPDF'             => $lastListPDF,
            'submitted_order_ids' => $allSubmittedOrderIds,
        ];
    }

    public function getPendingShipments(string $date = ''): array
    {
        return $this->storageService->getPendingShipments($date);
    }

    public function storeShipmentAfterLabel(array $shipmentData): void
    {
        $this->storageService->storeShipment($shipmentData);
    }
}