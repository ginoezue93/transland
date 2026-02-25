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

        // 1. Aktueller Wochentag (1 = Mo, 5 = Fr, 6 = Sa, 7 = So)
        $todayN = (int) date('N');

        // 2. Ziel-Datum berechnen (Werktag danach)
        if ($todayN >= 1 && $todayN <= 4) {
            // Mo bis Do -> Abholung morgen
            $borderoDate = date('Y-m-d', strtotime('+1 day'));
        } elseif ($todayN === 5) {
            // Freitag -> Abholung Montag (+3 Tage)
            $borderoDate = date('Y-m-d', strtotime('+3 days'));
        } elseif ($todayN === 6) {
            // Samstag -> Abholung Montag (+2 Tage)
            $borderoDate = date('Y-m-d', strtotime('+2 days'));
        } else {
            // Sonntag -> Abholung Montag (+1 Tag)
            $borderoDate = date('Y-m-d', strtotime('+1 day'));
        }

        $this->getLogger(__METHOD__)->info('TranslandShipping::bordero.date_calculated', [
            'today' => date('l'),
            'calculated_pickup' => $borderoDate
        ]);
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
            $borderoPayload = $this->payloadBuilder->buildBorderoPayload($shipments, $date, $listId);

            try {
                $apiResponse = $this->apiService->submitShippingList($borderoPayload, $returnList);
            } catch (\Throwable $apiEx) {
                // FIX: get_class() und dynamische Exception-Logs vermeiden
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.apiException', [
                    'list_id' => $listId,
                    'exception' => $apiEx->getMessage()
                ]);
                continue;
            }

            // Sicherstellen, dass api_result ein String ist (kein dynamisches Object)
            $apiResult = (string) ($apiResponse['result'] ?? $apiResponse['status'] ?? 'MISSING');

            if ($apiResult !== 'ok') {
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.unexpectedResult', [
                    'list_id' => $listId
                ]);
                continue;
            }

            $submittedOrderIds = array_column($shipments, 'order_id');

            // Check auf PDF - Zugriff über Array-Key ist sicher
            $currentPDF = $apiResponse['listPDF'] ?? null;

            if (!empty($currentPDF)) {
                try {
                    /** @var \TranslandShipping\Services\EmailService $emailService */
                    $emailService = pluginApp(\TranslandShipping\Services\EmailService::class);
                    $emailService->sendLabelEmail((string) $currentPDF, 0);

                    $this->getLogger(__METHOD__)->info('TranslandShipping::bordero.mail_sent', [
                        'list_id' => $listId
                    ]);
                } catch (\Exception $e) {
                    $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.mail_failed', [
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                $this->getLogger(__METHOD__)->warning('TranslandShipping::bordero.no_pdf_in_response', [
                    'list_id' => $listId
                ]);
            }

            $this->storageService->markShipmentsAsSubmitted($submittedOrderIds, $listId);

            $allSubmittedOrderIds = array_merge($allSubmittedOrderIds, $submittedOrderIds);
            $lastListId = $listId;
            $lastListPDF = $currentPDF;
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