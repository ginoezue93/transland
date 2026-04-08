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
}
