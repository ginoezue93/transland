<?php

namespace TranslandShipping\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
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

            // Label als Dokument am Auftrag speichern (Fehler hier blockieren nicht den Rest)
            if (!empty($result['label_data'])) {
                try {
                    $this->saveLabelAsDocument($order->id, $result['label_data'], $format);
                } catch (\Exception $docException) {
                    $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.labelSaveError', [
                        'orderId' => $order->id,
                        'error'   => $docException->getMessage(),
                    ]);
                }
            }

            // Sendungsdaten für Bordero speichern
            /** @var ShippingListService $shippingListService */
            $shippingListService = pluginApp(ShippingListService::class);
            $shippingListService->storeShipmentAfterLabel($result['shipment_data']);

            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.success', [
                'orderId'                => $order->id,
                'ssccList'               => $result['sscc_list'],
                'stored_shipper_name1'   => $result['shipment_data']['shipper_address']['name1']   ?? 'LEER',
                'stored_consignee_name1' => $result['shipment_data']['consignee_address']['name1'] ?? 'LEER',
                'stored_reference'       => $result['shipment_data']['reference'] ?? 'LEER',
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

        $authHelper = pluginApp(AuthHelper::class);
        $authHelper->processUnguarded(function () use ($orderId, $labelBase64) {
            pluginApp(DocumentRepositoryContract::class)->uploadOrderDocuments($orderId, 'deliveryNote', [[
                'displayDate'      => date('Y-m-d H:i:s'),
                'numberWithPrefix' => 'TL-' . $orderId . '-' . date('Ymd'),
                'content'          => $labelBase64,
            ]]);
        });

        $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.labelSaved', [
            'orderId' => $orderId,
        ]);
    }

    private function orderToArray(Order $order): array
    {
        // Laut offizieller PlentyONE Shipping-Plugin Dokumentation:
        // $order->deliveryAddress ist eine direkte Property des Order-Modells.
        // Felder: street, houseNumber, postalCode, town, countryId, firstName, lastName, companyName
        // phone/email über address->options (typeId 4 = phone, typeId 5 = email)

        $address = $order->deliveryAddress ?? $order->billingAddress ?? null;

        $phone = '';
        $email = '';
        if ($address && !empty($address->options)) {
            foreach ($address->options as $option) {
                if (($option->typeId ?? 0) == 4) {
                    $phone = $option->value ?? '';
                }
                if (($option->typeId ?? 0) == 5) {
                    $email = $option->value ?? '';
                }
            }
        }

        $addressArray = [];
        if ($address) {
            $addressArray = [
                'company'    => $address->companyName ?? '',
                'firstName'  => $address->firstName   ?? '',
                'lastName'   => $address->lastName    ?? '',
                'address1'   => $address->street      ?? '',
                'address2'   => $address->houseNumber ?? '',
                'postalCode' => $address->postalCode  ?? '',
                'town'       => $address->town        ?? '',
                'countryId'  => $address->countryId   ?? 1,
                'phone'      => $phone,
                'email'      => $email,
            ];
        }

        $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.orderParsed', [
            'orderId'     => $order->id,
            'hasAddress'  => $address !== null ? 'JA' : 'NEIN',
            'firstName'   => $addressArray['firstName']  ?? 'LEER',
            'lastName'    => $addressArray['lastName']   ?? 'LEER',
            'street'      => $addressArray['address1']   ?? 'LEER',
            'houseNumber' => $addressArray['address2']   ?? 'LEER',
            'postalCode'  => $addressArray['postalCode'] ?? 'LEER',
            'town'        => $addressArray['town']       ?? 'LEER',
            'phone'       => $phone,
            'externalId'  => $order->externalOrderId    ?? 'LEER',
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