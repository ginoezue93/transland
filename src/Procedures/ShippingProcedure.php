<?php

namespace TranslandShipping\Procedures;

use Plenty\Modules\Document\Contracts\DocumentRepositoryContract;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\ShippingListService;

class ShippingProcedure
{
    use Loggable;

    public function run(EventProceduresTriggered $event): void
    {
        /** @var Order|null $order */
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

            $packages = array_map(static function ($pkg) {
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

            // Label speichern
            if (!empty($result['label_data'])) {
                /** @var DocumentRepositoryContract $documentRepo */
                $documentRepo = pluginApp(DocumentRepositoryContract::class);

                $ssccSuffix = !empty($result['sscc_list']) ? '_' . $result['sscc_list'][0] : '';
                $filename   = 'Transland_Label_' . $order->id . $ssccSuffix . '.pdf';

                $rawBase64   = (string)$result['label_data'];
                $labelBase64 = $this->sanitizeBase64($rawBase64);

                // Quick sanity logs (ohne das ganze Label zu loggen)
                $this->getLogger(__CLASS__)->info('TranslandShipping::ShippingProcedure.labelBase64Info', [
                    'orderId'        => $order->id,
                    'rawPrefix'      => substr($rawBase64, 0, 30),
                    'cleanPrefix'    => substr($labelBase64, 0, 10),
                    'cleanLength'    => strlen($labelBase64),
                    'startsWithPDF'  => (strpos($labelBase64, 'JVBERi0') === 0), // PDF base64 header
                ]);

                if ($labelBase64 === '') {
                    $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.emptyLabel', [
                        'orderId'   => $order->id,
                        'rawPrefix' => substr($rawBase64, 0, 50),
                    ]);
                    return;
                }

                // 1) Primär: Versandlabel am Shipping-Package speichern (hier ist die API am klarsten)
                $saved = false;
                $firstPackageId = $plentyPackages[0]->id ?? null;

                try {
                    if (!$firstPackageId) {
                        throw new \RuntimeException('Kein packageId gefunden (plentyPackages[0]->id ist leer).');
                    }

                    // Erwartet: base64 encoded document (string) – OHNE data:...;base64, prefix
                    $documentRepo->uploadOrderShippingPackageDocuments(
                        (int)$firstPackageId,
                        'shippingLabel',
                        $labelBase64
                    );

                    $saved = true;

                    $this->getLogger(__CLASS__)->info('TranslandShipping::ShippingProcedure.labelSavedToPackage', [
                        'orderId'   => $order->id,
                        'packageId' => (int)$firstPackageId,
                        'filename'  => $filename,
                        'type'      => 'shippingLabel',
                    ]);
                } catch (\Throwable $e) {
                    // Optionaler Retry: manche Umgebungen erwarten ggf. einen anderen type
                    try {
                        if ($firstPackageId) {
                            $documentRepo->uploadOrderShippingPackageDocuments(
                                (int)$firstPackageId,
                                'label',
                                $labelBase64
                            );

                            $saved = true;

                            $this->getLogger(__CLASS__)->warning('TranslandShipping::ShippingProcedure.labelSavedToPackageAltType', [
                                'orderId'    => $order->id,
                                'packageId'  => (int)$firstPackageId,
                                'filename'   => $filename,
                                'type'       => 'label',
                                'prevError'  => $e->getMessage(),
                                'prevClass'  => get_class($e),
                            ]);
                        }
                    } catch (\Throwable $e2) {
                        $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.packageLabelSaveFailed', [
                            'orderId'    => $order->id,
                            'packageId'  => $firstPackageId,
                            'error'      => $e2->getMessage(),
                            'class'      => get_class($e2),
                            'prevError'  => $e->getMessage(),
                            'prevClass'  => get_class($e),
                        ]);
                    }
                }

                // 2) Fallback: Als Order-Dokument 'uploaded' (nur wenn Package-Upload nicht ging)
                // Hinweis: Plenty validiert hier streng das $data-Format; wir probieren gängige Varianten + loggen Validation-Details.
                if (!$saved) {
                    $this->tryUploadOrderDocumentUploaded($documentRepo, (int)$order->id, $filename, $labelBase64);
                }
            }

            // Sendungsdaten für Bordero speichern
            /** @var ShippingListService $shippingListService */
            $shippingListService = pluginApp(ShippingListService::class);
            $shippingListService->storeShipmentAfterLabel($result['shipment_data'] ?? []);

            $this->getLogger(__CLASS__)->info('TranslandShipping::ShippingProcedure.success', [
                'orderId'  => $order->id,
                'ssccList' => $result['sscc_list'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.error', [
                'orderId' => $order->id,
                'error'   => $e->getMessage(),
                'class'   => get_class($e),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Versucht das Label als Order-Dokument (type=uploaded) zu speichern.
     * Probiert mehrere Payload-Formate, weil die Doku das Request-Schema nicht eindeutig zeigt.
     */
    private function tryUploadOrderDocumentUploaded(
        DocumentRepositoryContract $documentRepo,
        int $orderId,
        string $filename,
        string $labelBase64
    ): void {
        $payloads = [
            // Variante A (häufig): Array von Dokumenten mit base64/filename/mimeType
            [
                [
                    'base64'   => $labelBase64,
                    'filename' => $filename,
                    'mimeType' => 'application/pdf',
                ]
            ],
            // Variante B: type statt mimeType
            [
                [
                    'base64'   => $labelBase64,
                    'filename' => $filename,
                    'type'     => 'application/pdf',
                ]
            ],
            // Variante C: content statt base64
            [
                [
                    'content'  => $labelBase64,
                    'filename' => $filename,
                    'mimeType' => 'application/pdf',
                ]
            ],
            // Variante D: content + fileName
            [
                [
                    'content'  => $labelBase64,
                    'fileName' => $filename,
                    'mimeType' => 'application/pdf',
                ]
            ],
            // Variante E: flaches Objekt statt Liste
            [
                'base64'   => $labelBase64,
                'filename' => $filename,
                'mimeType' => 'application/pdf',
            ],
        ];

        foreach ($payloads as $idx => $data) {
            try {
                $documentRepo->uploadOrderDocuments($orderId, 'uploaded', $data);

                $this->getLogger(__CLASS__)->warning('TranslandShipping::ShippingProcedure.labelSavedAsOrderUploaded', [
                    'orderId'      => $orderId,
                    'filename'     => $filename,
                    'payloadIndex' => $idx,
                ]);
                return;

            } catch (\Plenty\Exceptions\ValidationException $ve) {
                // Sehr wichtig: konkrete Validation-Errors loggen (damit du sofort siehst, welche Keys fehlen)
                $errors = null;
                if (method_exists($ve, 'getValidationErrors')) {
                    try {
                        $errors = $ve->getValidationErrors();
                    } catch (\Throwable $ignored) {
                        $errors = null;
                    }
                }

                $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.uploadedValidationFailed', [
                    'orderId'      => $orderId,
                    'payloadIndex' => $idx,
                    'error'        => $ve->getMessage(),
                    'class'        => get_class($ve),
                    'errors'       => $errors,
                    'dataKeys'     => is_array($data) ? array_keys($data) : null,
                ]);

                // weiter zur nächsten Payload-Variante
            } catch (\Throwable $e) {
                $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.uploadedSaveFailed', [
                    'orderId'      => $orderId,
                    'payloadIndex' => $idx,
                    'error'        => $e->getMessage(),
                    'class'        => get_class($e),
                ]);
                // weiter zur nächsten Payload-Variante
            }
        }

        $this->getLogger(__CLASS__)->error('TranslandShipping::ShippingProcedure.uploadedAllPayloadsFailed', [
            'orderId'  => $orderId,
            'filename' => $filename,
        ]);
    }

    /**
     * Entfernt data-URI Header (data:application/pdf;base64,...) + Whitespace/Linebreaks.
     */
    private function sanitizeBase64(string $base64): string
    {
        $base64 = trim($base64);

        // Entfernt z.B. "data:application/pdf;base64,"
        if (strpos($base64, 'data:') === 0) {
            $pos = strpos($base64, 'base64,');
            if ($pos !== false) {
                $base64 = substr($base64, $pos + 7);
            }
        }

        // Whitespace / Zeilenumbrüche entfernen
        $base64 = (string)preg_replace('/\s+/', '', $base64);

        return $base64;
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