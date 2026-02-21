<?php

namespace TranslandShipping\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\SettingsService;

/**
 * ShippingProcedure
 *
 * Triggered via PlentyMarkets Ereignisaktion (Event Procedure).
 * Registered under: Einrichtung → Aufträge → Ereignisse → Aktionen → Plugins → Transland
 *
 * This is an ALTERNATIVE to the Flow Studio step.
 * It creates a Transland label for the order and stores the shipment for Bordero.
 *
 * Note: This procedure uses the packages already registered on the order
 * via the shipping package repository. If packages are not yet registered
 * at event trigger time, use the Flow Studio REST step instead.
 */
class ShippingProcedure
{
    use Loggable;

    /**
     * Called by the Ereignisaktion event dispatcher.
     */
    public function run(EventProceduresTriggered $event): void
    {
        /** @var Order $order */
        $order = $event->getOrder();

        if (!$order || !$order->id) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.noOrder', [
                'message' => 'Kein Auftrag gefunden',
            ]);
            return;
        }

        try {
            /** @var OrderShippingPackageRepositoryContract $packageRepo */
            $packageRepo = pluginApp(OrderShippingPackageRepositoryContract::class);

            // Load packages registered on this order in Plenty
            $plentyPackages = $packageRepo->listOrderShippingPackages($order->id);

            if (empty($plentyPackages)) {
                $this->getLogger(__CLASS__)->warning('TranslandShipping::ShippingProcedure.noPackages', [
                    'orderId' => $order->id,
                    'message' => 'Keine Packstücke am Auftrag – Label kann nicht erstellt werden.',
                ]);
                return;
            }

            // FIX: convert Plenty package models to the array format LabelService expects
            $packages = array_map(function ($pkg) {
                return [
                    'content'        => 'Waren',
                    'packaging_type' => '',   // will be resolved from process/settings in LabelController
                    'length_cm'      => (int)($pkg->length ?? 0),
                    'width_cm'       => (int)($pkg->width ?? 0),
                    'height_cm'      => (int)($pkg->height ?? 0),
                    'weight_gr'      => (int)($pkg->weight ?? 0),
                ];
            }, $plentyPackages);

            // Convert Plenty Order model to array (LabelService works with arrays)
            $orderArray = $this->orderToArray($order);

            /** @var SettingsService $settingsService */
            $settingsService = pluginApp(SettingsService::class);
            $settings        = $settingsService->getSettings();
            $format          = strtoupper($settings['label_format'] ?? 'PDF');

            /** @var LabelService $labelService */
            $labelService = pluginApp(LabelService::class);

            // FIX: pass order array + packages array (not just order ID)
            $result = $labelService->createLabelForOrder($orderArray, $packages, $format, []);

            // Store shipment for Bordero submission
            /** @var ShippingListService $shippingListService */
            $shippingListService = pluginApp(ShippingListService::class);
            $shippingListService->storeShipmentAfterLabel($result['shipment_data']);

            $this->getLogger(__CLASS__)->info('TranslandShipping::ShippingProcedure.success', [
                'orderId'  => $order->id,
                'ssccList' => $result['sscc_list'],
            ]);

        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.error', [
                'orderId' => $order->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Convert PlentyMarkets Order model to a plain array
     * matching the structure expected by PayloadBuilderService.
     */
    private function orderToArray(Order $order): array
    {
        // Delivery address
        $deliveryAddress = null;
        foreach ($order->addresses as $relation) {
            // typeId 2 = delivery address in PlentyMarkets
            if (($relation->pivot->typeId ?? null) == 2) {
                $deliveryAddress = $relation;
                break;
            }
        }

        // Fallback to billing address (typeId 1)
        if (!$deliveryAddress) {
            foreach ($order->addresses as $relation) {
                if (($relation->pivot->typeId ?? null) == 1) {
                    $deliveryAddress = $relation;
                    break;
                }
            }
        }

        $addressArray = [];
        if ($deliveryAddress) {
            $addressArray = [
                'company'    => $deliveryAddress->companyName ?? '',
                'firstName'  => $deliveryAddress->firstName ?? '',
                'lastName'   => $deliveryAddress->lastName ?? '',
                'address1'   => $deliveryAddress->address1 ?? '',
                'address2'   => $deliveryAddress->address2 ?? '',
                'postalCode' => $deliveryAddress->postalCode ?? '',
                'town'       => $deliveryAddress->town ?? '',
                'countryId'  => $deliveryAddress->countryId ?? 1,
                'phone'      => $deliveryAddress->phone ?? '',
                'email'      => $deliveryAddress->email ?? '',
            ];
        }

        // Amounts
        $amounts = [];
        foreach (($order->amounts ?? []) as $amount) {
            $amounts[] = [
                'isNet'        => $amount->isNet ?? false,
                'invoiceTotal' => $amount->invoiceTotal ?? 0,
                'currency'     => $amount->currency ?? 'EUR',
            ];
        }

        return [
            'id'              => $order->id,
            'externalOrderId' => $order->externalOrderId ?? '',
            'deliveryAddress' => $addressArray,
            'amounts'         => $amounts,
            'notes'           => '',
        ];
    }
}