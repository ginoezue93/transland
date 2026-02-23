<?php

namespace TranslandShipping\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Document\Contracts\OrderDocumentRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
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
                $this->getLogger(__CLASS__)->warning('TranslandShipping::ShippingProcedure.noPackages', [
                    'orderId' => $order->id,
                ]);
                return;
            }

            $packages = array_map(function ($pkg) {
                return [
                    'content'        => 'Waren',
                    'packaging_type' => '',
                    'length_cm'      => (int)($pkg->length ?? 0),
                    'width_cm'       => (int)($pkg->width  ?? 0),
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
                $this->saveLabelAsDocument($order->id, $result['label_data'], $format);
            }

            // Sendungsdaten für Bordero speichern
            /** @var ShippingListService $shippingListService */
            $shippingListService = pluginApp(ShippingListService::class);
            $shippingListService->storeShipmentAfterLabel($result['shipment_data']);

            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.success', [
                'orderId'               => $order->id,
                'ssccList'              => $result['sscc_list'],
                'stored_shipper_name1'  => $result['shipment_data']['shipper_address']['name1']   ?? 'LEER',
                'stored_consignee_name1'=> $result['shipment_data']['consignee_address']['name1'] ?? 'LEER',
                'stored_reference'      => $result['shipment_data']['reference'] ?? 'LEER',
                'stored_package_count'  => count($result['shipment_data']['packages'] ?? []),
            ]);

        } catch (\Exception $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.error', [
                'orderId' => $order->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    private function saveLabelAsDocument(int $orderId, string $labelBase64, string $format): void
    {
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $filename   = 'Transland_Label_' . $orderId . '_' . date('Ymd') . '.pdf';
        $authHelper = pluginApp(AuthHelper::class);

        $authHelper->processUnguarded(function () use ($orderId, $labelBase64, $filename) {
            $documentRepo = pluginApp(OrderDocumentRepositoryContract::class);
            $documentRepo->uploadOrderDocuments($orderId, [[
                'content' => $labelBase64,
                'name'    => $filename,
                'type'    => 'uploaded_file',
            ]]);
        });

        $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.labelSaved', [
            'orderId'  => $orderId,
            'filename' => $filename,
        ]);
    }

    private function orderToArray(Order $order): array
    {
        // Lieferadresse suchen – mehrere Zugriffsmethoden probieren
        $deliveryAddress = null;

        // Methode 1: über addresses-Relation mit typeId
        if (!empty($order->addresses)) {
            foreach ($order->addresses as $address) {
                // typeId kann direkt am Objekt oder über pivot liegen
                $typeId = null;
                if (isset($address->pivot) && isset($address->pivot->typeId)) {
                    $typeId = $address->pivot->typeId;
                } elseif (isset($address->typeId)) {
                    $typeId = $address->typeId;
                }

                if ($typeId == 2) {  // 2 = Lieferadresse
                    $deliveryAddress = $address;
                    break;
                }
            }

            // Fallback: Rechnungsadresse (typeId 1)
            if (!$deliveryAddress) {
                foreach ($order->addresses as $address) {
                    $typeId = null;
                    if (isset($address->pivot) && isset($address->pivot->typeId)) {
                        $typeId = $address->pivot->typeId;
                    } elseif (isset($address->typeId)) {
                        $typeId = $address->typeId;
                    }

                    if ($typeId == 1) {
                        $deliveryAddress = $address;
                        break;
                    }
                }
            }

            // Letzter Fallback: einfach erste Adresse nehmen
            if (!$deliveryAddress && count($order->addresses) > 0) {
                $deliveryAddress = $order->addresses[0];
            }
        }

        // Adress-Felder auslesen – robust gegen verschiedene Modell-Strukturen
        $addressArray = [];
        if ($deliveryAddress) {
            $addressArray = [
                'company'    => $deliveryAddress->companyName ?? $deliveryAddress->company ?? '',
                'firstName'  => $deliveryAddress->firstName  ?? '',
                'lastName'   => $deliveryAddress->lastName   ?? '',
                'address1'   => $deliveryAddress->address1   ?? $deliveryAddress->street ?? '',
                'address2'   => $deliveryAddress->address2   ?? $deliveryAddress->houseNumber ?? '',
                'postalCode' => $deliveryAddress->postalCode ?? $deliveryAddress->zipCode ?? '',
                'town'       => $deliveryAddress->town       ?? $deliveryAddress->city ?? '',
                'countryId'  => $deliveryAddress->countryId  ?? 1,
                'phone'      => $deliveryAddress->phone      ?? '',
                'email'      => $deliveryAddress->email      ?? '',
            ];
        }

        // Diagnose-Log: zeigt was aus dem Auftrag extrahiert wurde
        $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.orderParsed', [
            'orderId'              => $order->id,
            'addressFound'         => $deliveryAddress !== null ? 'JA' : 'NEIN – keine Adresse gefunden!',
            'addressCount'         => count($order->addresses ?? []),
            'consignee_firstName'  => $addressArray['firstName']  ?? 'LEER',
            'consignee_lastName'   => $addressArray['lastName']   ?? 'LEER',
            'consignee_address1'   => $addressArray['address1']   ?? 'LEER',
            'consignee_postalCode' => $addressArray['postalCode'] ?? 'LEER',
            'consignee_town'       => $addressArray['town']       ?? 'LEER',
            'externalOrderId'      => $order->externalOrderId     ?? 'LEER',
        ]);

        $amounts = [];
        foreach (($order->amounts ?? []) as $amount) {
            $amounts[] = [
                'isNet'        => $amount->isNet        ?? false,
                'invoiceTotal' => $amount->invoiceTotal ?? 0,
                'currency'     => $amount->currency     ?? 'EUR',
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