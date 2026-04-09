<?php

namespace TranslandShipping\Services;

/**
 * PayloadBuilderService
 */
class PayloadBuilderService
{
    private const PACKAGING_TYPE_MAP = [
        'europalette' => 'FP',
        'einwegpalette' => 'EP',
        'chep-palette' => 'CP',
        'chep-halbpalette' => 'CH',
        'chep-viertelpalette' => 'CV',
        'duesseldorf-palette' => 'DP',
        'halbpalette' => 'HP',
        'viertelpalette' => 'VP',
        'gitterboxpalette' => 'GP',
        'kundeneigene-palette' => 'KP',
        'karton' => 'KT',
        'paket' => 'PA',
        'pack' => 'PK',
        'ballen' => 'BL',
        'bund' => 'BU',
        'fass' => 'FA',
        'eimer' => 'EI',
        'kiste' => 'KI',
        'sack' => 'SA',
        'stueck' => 'ST',
        'sonderbehälter' => 'KB',
    ];

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    // -------------------------------------------------------------------------
    // Label payload
    // -------------------------------------------------------------------------

    public function buildLabelPayload(array $order, array $packages, array $options = []): array
    {
        $settings = $this->settingsService->getSettings();

        return [
            'shipper_address' => $this->buildShipperAddress($settings),
            'consignee_address' => $this->buildConsigneeAddress($order),
            'pickup_date' => date('Y-m-d'),
            'franking' => '1',
            'reference' => $this->buildReference($order),
            'value' => $this->getOrderValue($order),  // int Cent, wird in LabelService umgerechnet
            'value_currency' => $this->getOrderCurrency($order),
            'weight_gr' => $this->calculateTotalWeightGram($packages),
            'options' => $options,
            'packages' => $this->buildPackages($packages, $order),
            'texts' => $this->buildTexts($order),
        ];
    }

    // -------------------------------------------------------------------------
    // Bordero payload
    // -------------------------------------------------------------------------

    public function buildBorderoPayload(array $shipments, string $pickupDate, string $listId): array
    {
        $settings = $this->settingsService->getSettings();

        return [
            'customer_id' => $settings['plenty_customer_id_at_transland'],
            'branch' => 'TRANSL1',
            'list_id' => $listId,
            'pickup_date' => $pickupDate,
            'shippings' => array_map(
                fn(array $shipment) => $this->buildShippingObjectFromStoredData($shipment),
                $shipments
            ),
        ];
    }

    public function buildShippingObjectFromStoredData(array $shipment): array
    {
        $obj = [
            'shipper_address' => $shipment['shipper_address'] ?? [],
            'consignee_address' => $shipment['consignee_address'] ?? [],
            'loading_address' => $shipment['loading_address'] ?? $shipment['shipper_address'] ?? [],
            'pickup_date' => $shipment['pickup_date'] ?? date('Y-m-d'),
            'procurement' => $shipment['procurement'] ?? false,
            'franking' => $shipment['franking'] ?? '1',
            'reference' => $shipment['reference'] ?? '',
            'value' => (int) round((float) ($shipment['value'] ?? 0)),
            'value_currency' => $shipment['value_currency'] ?? 'EUR',
            'weight_gr' => (int) ($shipment['weight_gr'] ?? 0),
            'packages' => $shipment['packages'] ?? [],
        ];

        // OPTIONEN REINIGEN
        if (!empty($shipment['options']) && is_array($shipment['options'])) {
            $cleanOptions = [];
            foreach ($shipment['options'] as $opt) {
                $code = (string) ($opt['code'] ?? '');

                // Wir prüfen, ob der Code nur aus Zahlen besteht
                // Da ctype_digit verboten ist, nutzen wir eine Kombination aus is_numeric und Typ-Check
                if (!empty($code) && is_numeric($code) && (int) $code > 0) {
                    $cleanOptions[] = [
                        'code' => $code,
                        'text' => (string) ($opt['text'] ?? '')
                    ];
                }
            }
            if (!empty($cleanOptions)) {
                $obj['options'] = $cleanOptions;
            }
        }

        if (!empty($shipment['texts'])) {
            $obj['texts'] = $shipment['texts'];
        }

        return $obj;
    }

    // -------------------------------------------------------------------------
    // Address builders
    // -------------------------------------------------------------------------

    public function buildShipperAddress(array $settings): array
    {
        $addr = [
            'name1' => $settings['shipper_name1'] ?? '',
            'street' => $settings['shipper_street'] ?? '',
            'country' => $settings['shipper_country'] ?? 'DE',
            'zip' => $settings['shipper_zip'] ?? '',
            'city' => $settings['shipper_city'] ?? '',
        ];

        // external_id = Kundennummer bei Transland (identifiziert das Lager/den Absender)
        if (!empty($settings['plenty_customer_id_at_transland'])) {
            $addr['external_id'] = $settings['plenty_customer_id_at_transland'];
        }
        if (!empty($settings['shipper_name2'])) {
            $addr['name2'] = $settings['shipper_name2'];
        }
        if (!empty($settings['shipper_contact'])) {
            $addr['contact_person'] = $settings['shipper_contact'];
        }
        if (!empty($settings['shipper_phone'])) {
            $addr['phone'] = $settings['shipper_phone'];
        }
        if (!empty($settings['shipper_email'])) {
            $addr['email'] = $settings['shipper_email'];
        }

        return $addr;
    }

    public function buildConsigneeAddress(array $order): array
    {
        $delivery = $order['deliveryAddress'] ?? $order['billingAddress'] ?? [];

        $name1 = trim(($delivery['company'] ?? '') ?: implode(' ', array_filter([
            $delivery['firstName'] ?? '',
            $delivery['lastName'] ?? '',
        ])));

        $name2 = '';
        if (!empty($delivery['company'])) {
            $name2 = trim(($delivery['firstName'] ?? '') . ' ' . ($delivery['lastName'] ?? ''));
        }

        $addr = [
            'name1' => substr($name1, 0, 35),
            'street' => substr(trim(($delivery['address1'] ?? '') . ' ' . ($delivery['address2'] ?? '')), 0, 35),
            'country' => isset($delivery['countryId']) ? $this->mapCountryId((int) $delivery['countryId']) : 'DE',
            'zip' => substr($delivery['postalCode'] ?? '', 0, 9),
            'city' => substr($delivery['town'] ?? '', 0, 35),
        ];

        if (!empty($name2)) {
            $addr['name2'] = substr($name2, 0, 35);
        }
        $contactPerson = trim(($delivery['firstName'] ?? '') . ' ' . ($delivery['lastName'] ?? ''));
        if (!empty($contactPerson)) {
            $addr['contact_person'] = substr($contactPerson, 0, 256);
        }
        if (!empty($delivery['phone'])) {
            $addr['phone'] = substr($delivery['phone'], 0, 256);
        }
        if (!empty($delivery['email'])) {
            $addr['email'] = substr($delivery['email'], 0, 256);
        }

        return $addr;
    }

    // -------------------------------------------------------------------------
    // Package builder
    // -------------------------------------------------------------------------

    /**
     * Build the "positions" array for the Zufall label payload.
     *
     * Each plentymarkets shipping-center package becomes one position.
     * Follows the Zufall v2 positions spec:
     *   - quantity (pflicht, default 1)
     *   - length/width/height/weight (pflicht)
     *   - content (pflicht)
     *   - packaging_type (pflicht, from package template)
     *   - cubic_cm (calculated from dimensions)
     *   - reference (optional but recommended: goes on the Zufall invoice)
     *   - sub_packaging_count / sub_packaging_type (optional)
     *   - packages (NVE/SSCC barcodes — filled after label creation)
     *   - dangerous_goods (placeholder array, filled when hazmat spec is known)
     */
    public function buildPackages(array $packages, array $order = []): array
    {
        // The Zufall "reference" field is a customer reference that ends up
        // on the transport invoice. Per project decision we always use the
        // Plenty order id — this lets the customer reconcile Zufall invoices
        // against Plenty orders without any lookup table.
        $orderReference = '';
        if (!empty($order['id'])) {
            $orderReference = (string) $order['id'];
        }

        return array_map(function (array $pkg) use ($orderReference) {
            $lengthCm = (int) ($pkg['length_cm'] ?? 0);
            $widthCm = (int) ($pkg['width_cm'] ?? 0);
            $heightCm = (int) ($pkg['height_cm'] ?? 0);

            $built = [
                'quantity' => (int) ($pkg['quantity'] ?? 1),
                'content' => substr($pkg['content'] ?? 'Waren', 0, 35),
                'packaging_type' => $this->mapPackagingType($pkg['packaging_type'] ?? 'FP'),
                'length_cm' => $lengthCm,
                'width_cm' => $widthCm,
                'height_cm' => $heightCm,
                'weight_gr' => (int) ($pkg['weight_gr'] ?? 0),
                'cubic_cm' => $lengthCm * $widthCm * $heightCm,
            ];

            // reference – Zufall spec: customer reference that appears on the
            // transport invoice. We use the Plenty order id here.
            // Override via pkg['reference'] is still possible if a caller
            // explicitly wants something else.
            $pkgRef = $pkg['reference'] ?? $pkg['package_reference'] ?? $orderReference;
            if (!empty($pkgRef)) {
                $built['reference'] = substr((string) $pkgRef, 0, 35);
            }

            // sub_packaging – only set when actually provided
            if (!empty($pkg['sub_packaging_count'])) {
                $built['sub_packaging_count'] = (int) $pkg['sub_packaging_count'];
                $built['sub_packaging_type'] = $this->mapPackagingType($pkg['sub_packaging_type'] ?? 'KT');
            }

            // packages – array of NVE/SSCC barcodes belonging to this position.
            // Per Zufall spec: "Packstücke je Position (Barcode/NVE)".
            // At payload-build time we usually don't have the SSCC yet
            // (Zufall assigns it), so this is normally an empty array.
            // When a caller pre-fills pkg['sscc'] or pkg['barcodes'] we
            // include them so round-trip scenarios (e.g. Bordero resubmit)
            // still work.
            $barcodes = [];
            if (!empty($pkg['barcodes']) && is_array($pkg['barcodes'])) {
                foreach ($pkg['barcodes'] as $bc) {
                    if (!empty($bc)) {
                        $barcodes[] = ['barcode' => (string) $bc];
                    }
                }
            } elseif (!empty($pkg['sscc'])) {
                $barcodes[] = ['barcode' => (string) $pkg['sscc']];
            }
            $built['packages'] = $barcodes;

            // dangerous_goods – placeholder until Zufall hazmat spec is
            // integrated. Empty array means "no hazmat".
            // When the order has the Gefahrenstoff tag, ShippingController
            // will populate this array before the payload is built.
            if (isset($pkg['dangerous_goods']) && is_array($pkg['dangerous_goods'])) {
                $built['dangerous_goods'] = $pkg['dangerous_goods'];
            } else {
                $built['dangerous_goods'] = [];
            }

            return $built;
        }, $packages);
    }

    // -------------------------------------------------------------------------
    // Options builder
    // -------------------------------------------------------------------------

    /**
     * Baut die Standard-Optionen für eine Auslieferungssendung.
     * Wird von LabelService aufgerufen wenn keine manuellen Options übergeben werden.
     *
     * 101 = Avisierung per Telefon
     * 502 = Referenznummer (Auftragsnummer)
     * TLE = Liftgate/Heckklappenentladung
     */
    public function buildDefaultOptions(array $order): array
    {
        $options = [];

        $delivery = $order['deliveryAddress'] ?? [];
        $phone = $delivery['phone'] ?? '';
        $ref = !empty($order['externalOrderId']) ? $order['externalOrderId'] : (string) ($order['id'] ?? '');

        // 101: Telefonavisierung wenn Telefonnummer vorhanden (code als Integer laut Doku)
        if (!empty($phone)) {
            $options[] = ['code' => 101, 'text' => $phone];
        }

        // 502: Referenznummer immer setzen (code als Integer laut Doku)
        if (!empty($ref)) {
            $options[] = ['code' => 502, 'text' => $ref];
        }

        // TLE: kein gültiger Code laut Doku – mit Transland klären, vorerst deaktiviert
        // $options[] = ['code' => 'TLE'];

        return $options;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildReference(array $order): string
    {
        if (!empty($order['externalOrderId'])) {
            return (string) $order['externalOrderId'];
        }
        return (string) ($order['id'] ?? '');
    }

    private function getOrderValue(array $order): int
    {
        foreach (($order['amounts'] ?? []) as $amount) {
            if (($amount['isNet'] ?? false) === false) {
                return (int) round(($amount['invoiceTotal'] ?? 0) * 100);
            }
        }
        return 0;
    }

    private function getOrderCurrency(array $order): string
    {
        foreach (($order['amounts'] ?? []) as $amount) {
            return $amount['currency'] ?? 'EUR';
        }
        return 'EUR';
    }

    private function calculateTotalWeightGram(array $packages): int
    {
        return (int) array_sum(array_column($packages, 'weight_gr'));
    }

    private function buildTexts(array $order): array
    {
        $texts = [];
        if (!empty($order['notes'])) {
            $chunks = str_split($order['notes'], 70);
            $texts = array_slice($chunks, 0, 5);
        }
        return $texts;
    }

    private function mapPackagingType(string $type): string
    {
        $upper = strtoupper(trim($type));
        $validCodes = ['BL', 'BU', 'CH', 'CP', 'CV', 'DP', 'EI', 'EP', 'FA', 'FP', 'GP', 'HP', 'KB', 'KI', 'KP', 'KT', 'PA', 'PK', 'SA', 'ST', 'VP'];

        if (in_array($upper, $validCodes, true)) {
            return $upper;
        }

        return self::PACKAGING_TYPE_MAP[strtolower(trim($type))] ?? 'FP';
    }

    private function mapCountryId(int $countryId): string
    {
        $map = [
            1 => 'DE',
            2 => 'AT',
            4 => 'CH',
            5 => 'CY',
            6 => 'CZ',
            7 => 'DK',
            8 => 'ES',
            9 => 'EE',
            10 => 'FR',
            11 => 'FI',
            12 => 'BE',
            13 => 'GR',
            14 => 'GB',
            15 => 'IE',
            17 => 'IT',
            18 => 'LV',
            19 => 'LT',
            20 => 'LU',
            21 => 'MT',
            22 => 'NL',
            23 => 'PL',
            24 => 'PT',
            25 => 'RO',
            26 => 'SE',
            27 => 'SK',
            28 => 'SI',
            29 => 'HU',
            34 => 'US',
            35 => 'CA',
            36 => 'AU',
            66 => 'NO',
            74 => 'TR',
        ];

        return $map[$countryId] ?? 'DE';
    }
}