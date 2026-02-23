<?php

namespace TranslandShipping\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Templates\Twig;
use TranslandShipping\Services\StorageService;

/**
 * LabelPrintController
 *
 * Provides the UI page and PDF download endpoint for daily label printing.
 * Accessible at: /transland/labels
 */
class LabelPrintController extends Controller
{
    /**
     * Show the label print UI page.
     * GET /transland/labels
     */
    public function index(Twig $twig, Request $request): string
    {
        $date = $request->get('date', date('Y-m-d'));

        /** @var StorageService $storage */
        $storage = pluginApp(StorageService::class);
        $labels  = $storage->getLabelsByDate($date);

        return $twig->render('TranslandShipping::labels', [
            'date'       => $date,
            'labelCount' => count($labels),
            'hasLabels'  => !empty($labels),
            'labels'     => array_map(function ($l) {
                return ['order_id' => $l['order_id']];
            }, $labels),
        ]);
    }

    /**
     * Download a single label PDF by order ID and date.
     * GET /transland/labels/download?date=2026-02-22&order_id=122
     */
    public function download(Request $request, Response $response): Response
    {
        $date    = $request->get('date', date('Y-m-d'));
        $orderId = (int)$request->get('order_id', 0);

        /** @var StorageService $storage */
        $storage = pluginApp(StorageService::class);
        $labels  = $storage->getLabelsByDate($date);

        // If order_id given, return just that one label
        if ($orderId > 0) {
            foreach ($labels as $label) {
                if ((int)$label['order_id'] === $orderId) {
                    $pdfData = base64_decode($label['label_data']);
                    return $response->make($pdfData, 200, [
                        'Content-Type'        => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="Label_' . $orderId . '.pdf"',
                    ]);
                }
            }
            return $response->make('Label nicht gefunden.', 404);
        }

        // No order_id: return first label (page redirects through all via JS)
        if (empty($labels)) {
            return $response->make('Keine Labels für dieses Datum gefunden.', 404);
        }

        $pdfData = base64_decode($labels[0]['label_data']);
        return $response->make($pdfData, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Label_' . $labels[0]['order_id'] . '.pdf"',
        ]);
    }
}