<?php

namespace TranslandShipping\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\SettingsService;

/**
 * LabelController
 *
 * Handles POST /rest/transland/label
 *
 * Called during the packing process (process IDs 52, 73, 79, 85, 87).
 * Returns a base64-encoded label PDF or ZPL and stores shipment data for later Bordero submission.
 *
 * Example Request Body:
 * {
 *   "order_id": 12345,
 *   "order": { ... },      // full PlentyMarkets order object
 *   "process_id": 52,      // packing process ID
 *   "packages": [
 *     {
 *       "content": "Elektronikteile",
 *       "packaging_type": "KT",
 *       "length_cm": 60,
 *       "width_cm": 40,
 *       "height_cm": 30,
 *       "weight_gr": 5000
 *     }
 *   ],
 *   "options": []          // optional Sendungsoptionen
 * }
 */
class LabelController extends Controller
{
    use Loggable;

    private LabelService        $labelService;
    private ShippingListService $shippingListService;
    private SettingsService     $settingsService;

    public function __construct(
        LabelService        $labelService,
        ShippingListService $shippingListService,
        SettingsService     $settingsService
    ) {
        $this->labelService        = $labelService;
        $this->shippingListService = $shippingListService;
        $this->settingsService     = $settingsService;
    }

    /**
     * POST /rest/transland/label
     *
     * Creates a shipping label and stores the shipment for later Bordero submission.
     */
    public function createLabel(Request $request, Response $response): Response
    {
        try {
            $data      = $request->all();
            $order     = $data['order']    ?? [];
            $packages  = $data['packages'] ?? [];
            $options   = $data['options']  ?? [];
            $processId = (int)($data['process_id'] ?? 0);

            // Basic validation
            if (empty($order) || empty($packages)) {
                return $response->json([
                    'success' => false,
                    'message' => 'Missing required fields: order, packages',
                ], 400);
            }

            // Apply process-specific default packaging type if not explicitly set per package
            if ($processId > 0) {
                $defaultType = $this->settingsService->getPackagingTypeForProcess($processId);
                foreach ($packages as &$pkg) {
                    if (empty($pkg['packaging_type'])) {
                        $pkg['packaging_type'] = $defaultType;
                    }
                }
                unset($pkg);
            }

            // Determine label format from settings
            $settings = $this->settingsService->getSettings();
            $format   = strtoupper($settings['label_format'] ?? 'PDF');

            // Create label via Transland API
            $result = $this->labelService->createLabelForOrder($order, $packages, $format, $options);

            // Persist the shipment data for later Bordero submission (Tagesabschluss)
            $this->shippingListService->storeShipmentAfterLabel($result['shipment_data']);

            return $response->json([
                'success'      => true,
                'label_data'   => $result['label_data'],
                'label_format' => $result['label_format'],
                'packages'     => $result['packages'],
                'sscc_list'    => $result['sscc_list'],
                'order_id'     => $result['order_id'],
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::label.error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $response->json([
                'success' => false,
                'message' => 'Label creation failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
