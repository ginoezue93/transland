<?php

namespace TranslandShipping\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\ShippingListService;

/**
 * ShippingListController
 *
 * Handles endpoints for Bordero/Versandliste management.
 *
 * GET  /rest/transland/pending       → list pending (not yet submitted) shipments
 * POST /rest/transland/submit-day    → manually trigger Tagesabschluss / Bordero submission
 * POST /rest/transland/shipping-list → submit a specific list (advanced use)
 */
class ShippingListController extends Controller
{
    use Loggable;

    private ShippingListService $shippingListService;

    public function __construct(ShippingListService $shippingListService)
    {
        $this->shippingListService = $shippingListService;
    }

    /**
     * GET /rest/transland/pending
     *
     * Returns all shipments that have a label but are not yet in a Bordero.
     * Used to show operators what will be included in the next Tagesabschluss.
     */
    public function getPendingShipments(Request $request, Response $response): Response
    {
        try {
            $date     = $request->get('date', date('Y-m-d'));
            $pending  = $this->shippingListService->getPendingShipments($date);

            return $response->json([
                'success'    => true,
                'date'       => $date,
                'count'      => count($pending),
                'shipments'  => array_map(function (array $s) {
                    return [
                        'order_id'         => $s['order_id'],
                        'reference'        => $s['reference'],
                        'consignee'        => $s['consignee_address']['name1'] ?? '',
                        'package_count'    => count($s['packages'] ?? []),
                        'weight_gr'        => $s['weight_gr'],
                        'label_printed_at' => $s['label_printed_at'],
                    ];
                }, $pending),
            ]);

        } catch (\Throwable $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /rest/transland/submit-day
     *
     * Manually triggers the Tagesabschluss: submits all pending shipments
     * for the given date as a Bordero to Transland.
     *
     * Body (optional):
     * {
     *   "pickup_date": "2025-07-03",    // defaults to today
     *   "return_list": true             // request Ladeliste PDF in response
     * }
     */
    public function submitDailyShipments(Request $request, Response $response): Response
    {
        try {
            $data        = $request->all();
            $pickupDate  = $data['pickup_date'] ?? date('Y-m-d');
            $returnList  = (bool)($data['return_list'] ?? true);

            $result = $this->shippingListService->submitDailyShipments($pickupDate, $returnList);

            if ($result['result'] === 'no_pending') {
                return $response->json([
                    'success' => true,
                    'message' => 'Keine ausstehenden Sendungen für ' . $pickupDate,
                    'count'   => 0,
                ]);
            }

            $resp = [
                'success'              => true,
                'list_id'              => $result['list_id'],
                'shipment_count'       => $result['shipment_count'],
                'submitted_order_ids'  => $result['submitted_order_ids'],
            ];

            // Include Ladeliste PDF if requested and returned
            if (!empty($result['listPDF'])) {
                $resp['list_pdf'] = $result['listPDF'];
            }

            return $response->json($resp);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $response->json([
                'success' => false,
                'message' => 'Bordero submission failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /rest/transland/shipping-list
     *
     * Advanced: submit a fully custom shipping list payload directly.
     * Useful for manual corrections or re-submissions.
     */
    public function submitShippingList(Request $request, Response $response): Response
    {
        try {
            $data       = $request->all();
            $date       = $data['pickup_date'] ?? date('Y-m-d');
            $returnList = (bool)($data['return_list'] ?? true);

            $result = $this->shippingListService->submitDailyShipments($date, $returnList);

            return $response->json(['success' => true, 'result' => $result]);

        } catch (\Throwable $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
