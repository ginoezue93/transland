<?php

namespace TranslandShipping\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Fulfillment\Services\FulfillmentShipmentService;

/**
 * DiagnosticsController
 * Hilfsmethoden fuer Debugging und Diagnose.
 */
class DiagnosticsController extends Controller
{
    use Loggable;

    /**
     * GET /rest/transland/diagnostics/providers
     * Listet alle registrierten Shipping-Provider auf.
     * Damit koennen wir pruefen ob TranslandShipping korrekt registriert ist.
     */
    public function listProviders(Request $request, Response $response): Response
    {
        try {
            /** @var FulfillmentShipmentService $fulfillmentService */
            $fulfillmentService = pluginApp(FulfillmentShipmentService::class);
            $providers = $fulfillmentService->getShippingServiceProviders(true);

            $this->getLogger(__CLASS__)->error('TranslandShipping::diagnostics.providers', [
                'providers' => $providers,
                'count'     => count($providers),
            ]);

            return $response->json([
                'success'   => true,
                'providers' => $providers,
                'count'     => count($providers),
            ]);

        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /rest/transland/diagnostics/register/{orderId}
     * Triggert registerShipment() direkt ueber FulfillmentShipmentService.
     * Das ist der korrekte Weg um RegisterShipment aus einem Prozess heraus aufzurufen.
     */
    public function triggerRegister(Request $request, Response $response, int $orderId): Response
    {
        try {
            /** @var FulfillmentShipmentService $fulfillmentService */
            $fulfillmentService = pluginApp(FulfillmentShipmentService::class);

            $this->getLogger(__CLASS__)->error('TranslandShipping::diagnostics.triggerRegister', [
                'orderId' => $orderId,
            ]);

            $result = $fulfillmentService->registerShipment($orderId, 'TranslandShipping');

            return $response->json([
                'success' => true,
                'result'  => $result,
                'orderId' => $orderId,
            ]);

        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::diagnostics.error', [
                'orderId' => $orderId,
                'message' => $e->getMessage(),
            ]);

            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /rest/transland/diagnostics/bordero
     * Manueller Bordero-Trigger. Sendet alle pending Sendungen sofort an Zufall.
     * Kann jederzeit aufgerufen werden — unabhaengig vom Cron.
     */
    public function triggerBordero(Request $request, Response $response): Response
    {
        try {
            $this->getLogger(__CLASS__)->error('TranslandShipping::diagnostics.borderoManual', [
                'triggeredBy' => 'manual',
                'time'        => date('Y-m-d H:i:s'),
            ]);

            /** @var \TranslandShipping\Services\SettingsService $settingsService */
            $settingsService = pluginApp(\TranslandShipping\Services\SettingsService::class);
            $settings        = $settingsService->getSettings();
            $returnList      = (bool)($settings['return_ladeliste_pdf'] ?? true);

            /** @var \TranslandShipping\Services\ShippingListService $shippingListService */
            $shippingListService = pluginApp(\TranslandShipping\Services\ShippingListService::class);
            $result = $shippingListService->submitDailyShipments('', $returnList);

            $this->getLogger(__CLASS__)->error('TranslandShipping::diagnostics.borderoResult', [
                'result'         => $result['result'] ?? 'unknown',
                'shipment_count' => $result['shipment_count'] ?? 0,
                'list_id'        => $result['list_id'] ?? '',
            ]);

            return $response->json([
                'success'        => true,
                'result'         => $result['result'] ?? 'unknown',
                'shipment_count' => $result['shipment_count'] ?? 0,
                'list_id'        => $result['list_id'] ?? '',
                'message'        => ($result['result'] ?? '') === 'no_pending'
                    ? 'Keine pending Sendungen gefunden. Erst Auftraege registrieren.'
                    : 'Bordero an Zufall gesendet.',
            ]);

        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::diagnostics.borderoError', [
                'message' => $e->getMessage(),
            ]);

            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /rest/transland/diagnostics/pending
     * Zeigt alle pending Sendungen die beim naechsten Bordero gesendet wuerden.
     */
    public function listPending(Request $request, Response $response): Response
    {
        try {
            /** @var \TranslandShipping\Services\StorageService $storageService */
            $storageService = pluginApp(\TranslandShipping\Services\StorageService::class);
            $pending = $storageService->getPendingShipments('');

            return $response->json([
                'success' => true,
                'count'   => count($pending),
                'shipments' => array_map(function ($s) {
                    return [
                        'orderId'       => $s['order_id'] ?? 0,
                        'reference'     => $s['reference'] ?? '',
                        'pickupDate'    => $s['pickup_date'] ?? '',
                        'weightGr'      => $s['weight_gr'] ?? 0,
                        'labelPrinted'  => $s['label_printed'] ?? 0,
                        'submitted'     => $s['submitted'] ?? 0,
                    ];
                }, $pending),
            ]);

        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
