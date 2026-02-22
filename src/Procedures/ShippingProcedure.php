<?php

namespace TranslandShipping\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\SettingsService;

class ShippingProcedure
{
    use Loggable;

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
            $packageRepo    = pluginApp(OrderShippingPackageRepositoryContract::class);
            $plentyPackages = $packageRepo->listOrderShippingPackages($order->id);

            if (empty($plentyPackages)) {
                $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.noPackages', [
                    'orderId' => $order->id,
                ]);
                return;
            }

            $packages = array_map(function ($pkg) {
                return [
                    'content'        => 'Waren',
                    'packaging_type' => '',
                    'length_cm'      => (int)($pkg->length ?? 0),
                    'width_cm'       => (int)($pkg->width ?? 0),
                    'height_cm'      => (int)($pkg->height ?? 0),
                    'weight_gr'      => (int)($pkg->weight ?? 0),
                ];
            }, $plentyPackages);

            $orderArray = $this->orderToArray($order);

            /** @var SettingsService $settingsService */
            $settingsService = pluginApp(SettingsService::class);
            $settings        = $settingsService->getSettings();
            $format          = strtoupper($settings['label_format'] ?? 'PDF');

            /** @var LabelService $labelService */
            $labelService = pluginApp(LabelService::class);
            $result       = $labelService->createLabelForOrder($orderArray, $packages, $format, []);

            // SSCC zurück in die Plenty-Pakete schreiben
            foreach ($plentyPackages as $idx => $plentyPkg) {
                if (isset($result['packages'][$idx]['sscc'])) {
                    $packageRepo->updateOrderShippingPackage(
                        $plentyPkg->id,
                        ['packageNumber' => $result['packages'][$idx]['sscc']]
                    );
                }
            }

            // Label als Dokument am Auftrag speichern
            if (!empty($result['label_data'])) {
                $this->saveLabelAsDocument($order->id, $result['label_data'], $result['sscc_list']);
            }

            // Sendungsdaten für Bordero speichern
            /** @var ShippingListService $shippingListService */
            $shippingListService = pluginApp(ShippingListService::class);
            $shippingListService->storeShipmentAfterLabel($result['shipment_data']);

            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.success', [
                'orderId'    => $order->id,
                'ssccList'   => $result['sscc_list'],
                'labelSaved' => !empty($result['label_data']),
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
     * Speichert das Label PDF als Dokument am Auftrag.
     * Typ 'shipping_label' erscheint unter Auftrag → Dokumente.
     */
    private function saveLabelAsDocument(int $orderId, string $base64Pdf, array $ssccList): void
    {
        try {
            /** @var DocumentRepositoryContract $documentRepo */
            $documentRepo = pluginApp(DocumentRepositoryContract::class);

            $ssccSuffix = !empty($ssccList) ? '_' . $ssccList[0] : '';
            $filename   = 'Transland_Label_' . $orderId . $ssccSuffix . '.pdf';

            $documentRepo->uploadOrderDocuments(
                $orderId,
                'shipping_label',   // document type
                [
                    [
                        'content' => $base64Pdf,
                        'name'    => $filename,
                    ]
                ]
            );

            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.labelSaved', [
                'orderId'  => $orderId,
                'filename' => $filename,
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.labelSaveError', [
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function orderToArray(Order $order): array
    {
        $deliveryAddress = null;
        foreach ($order->addresses as $relation) {
            if (($relation->pivot->typeId ?? null) == 2) {
                $deliveryAddress = $relation;
                break;
            }
        }
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