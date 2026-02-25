<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;

class ShippingListService
{
    use Loggable;

    private TranslandApiService $apiService;
    private PayloadBuilderService $payloadBuilder;
    private StorageService $storageService;

    public function __construct(
        TranslandApiService $apiService,
        PayloadBuilderService $payloadBuilder,
        StorageService $storageService
    ) {
        $this->apiService = $apiService;
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
                'result' => 'no_pending',
                'list_id' => '',
                'shipment_count' => 0,
                'listPDF' => null,
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
        $lastListId = '';
        $lastListPDF = null;
        $totalCount = 0;

        foreach ($grouped as $date => $shipments) {
            $listId = 'LIST-' . str_replace('-', '', $date) . '-' . time();

            $borderoPayload = $this->payloadBuilder->buildBorderoPayload(
                $shipments,
                $date,
                $listId
            );

            // FULL PAYLOAD LOG – zeigt exakt was an die API gesendet wird
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.sending', [
                'plugin_version' => '3.5.0',
                'pickup_date' => $date,
                'shipment_count' => count($shipments),
                'list_id' => $listId,
                'raw_shipments_from_db' => $shipments,
                'payload_shippings' => $borderoPayload['shippings'] ?? [],
                'first_shipping_keys' => !empty($borderoPayload['shippings'])
                    ? array_keys($borderoPayload['shippings'][0])
                    : [],
                'first_shipper_address' => $borderoPayload['shippings'][0]['shipper_address'] ?? 'MISSING',
                'full_payload_json' => json_encode($borderoPayload),
            ]);

            try {
                $apiResponse = $this->apiService->submitShippingList($borderoPayload, $returnList);
            } catch (\Throwable $apiEx) {
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.apiException', [
                    'list_id' => $listId,
                    'exception' => $apiEx->getMessage(),
                    'class' => get_class($apiEx),
                    'file' => $apiEx->getFile(),
                    'line' => $apiEx->getLine(),
                    'trace' => substr($apiEx->getTraceAsString(), 0, 1000),
                ]);
                continue;
            }

            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.response', [
                'list_id' => $listId,
                'api_result' => $apiResponse['result'] ?? $apiResponse['status'] ?? 'MISSING',
                'has_listPDF' => !empty($apiResponse['listPDF']) ? 'JA' : 'NEIN',
                'sscc_count' => count($apiResponse['SSCCs'] ?? []),
                'full_response' => substr(json_encode($apiResponse), 0, 300),
            ]);

            if (($apiResponse['result'] ?? $apiResponse['status'] ?? '') !== 'ok') {
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.unexpectedResult', [
                    'list_id' => $listId,
                    'full_response' => json_encode($apiResponse),
                ]);
                continue;
            }

            $submittedOrderIds = array_column($shipments, 'order_id');
            if (!empty($apiResponse['listPDF'])) {
                try {
                    // Wir holen den Service direkt per pluginApp, falls die Injection hakt
                    $emailService = pluginApp(\TranslandShipping\Services\EmailService::class);

                    // WICHTIG: Die 0 signalisiert dem EmailService: "Das ist ein Bordero!"
                    $emailService->sendLabelEmail($$apiResponse['listPDF'], 0);

                    $this->getLogger(__METHOD__)->info('TranslandShipping::bordero.mail_sent', [
                        'list_id' => $listId
                    ]);
                } catch (\Exception $e) {
                    $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.mail_failed', [
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                // DAS HIER IST WICHTIG: Wenn das PDF fehlt, müssen wir es wissen!
                $this->getLogger(__METHOD__)->warning('TranslandShipping::bordero.no_pdf_in_response', [
                    'list_id' => $listId,
                    'response' => json_encode($apiResponse)
                ]);
            }
            $this->storageService->markShipmentsAsSubmitted($submittedOrderIds, $listId);

            $allSubmittedOrderIds = array_merge($allSubmittedOrderIds, $submittedOrderIds);
            $lastListId = $listId;
            $lastListPDF = $apiResponse['listPDF'] ?? null;
            $totalCount += count($shipments);
        }

        return [
            'result' => 'ok',
            'list_id' => $lastListId,
            'shipment_count' => $totalCount,
            'listPDF' => $lastListPDF,
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