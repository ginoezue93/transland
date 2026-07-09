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
    // NextDay Tag → Zufall option code mapping
    //
    // venturama marks orders as "NextDay" by tagging them. The 4 tags below
    // map to Zufall Premium Service option codes (spec page 11).
    //
    // Only ONE of these tags should be set per order. If multiple are set,
    // the most specific one (lowest number = earliest delivery time) wins.
    // -------------------------------------------------------------------------
    private const NEXTDAY_TAG_CODES = [
        // Tag name        => Zufall option code
        'NextDay8'  => 253,  // PS NextDay/8  (bis 8:00)
        'NextDay10' => 255,  // PS NextDay/10 (bis 10:00)
        'NextDay12' => 257,  // PS NextDay/12 (bis 12:00)
        'NextDay'   => 250,  // PS NextDay (Standard)
    ];

    /**
     * Build the "options" array for a Zufall shipment based on the tags
     * present on the order. Currently handles NextDay tags only.
     *
     * @param array $tagNames Flat list of tag name strings attached to the order.
     * @return array Array of {code, text?} objects ready for the Zufall payload.
     */
    public function buildShipmentOptions(array $tagNames): array
    {
        $options = [];

        // Normalize tag names to lowercase for case-insensitive matching
        $lowerTags = array_map('strtolower', $tagNames);

        // NextDay: use the most specific tag that is set. Order matters –
        // NextDay8 (most specific) is checked first. Since venturama is
        // supposed to set only ONE NextDay tag per order, hitting the first
        // match and returning is the safest behaviour.
        foreach (self::NEXTDAY_TAG_CODES as $tagName => $code) {
            if (in_array(strtolower($tagName), $lowerTags, true)) {
                $options[] = ['code' => $code];
                break;
            }
        }

        return $options;
    }

    // -------------------------------------------------------------------------
    // Hazmat / dangerous_goods from plugin config
    //
    // venturama has exactly one kind of hazmat product. When an order is
    // tagged "Gefahrenstoff", the plugin config "Gefahrgut" tab provides
    // all the fields needed for the Zufall dangerous_goods block (spec
    // page 14).
    //
    // This method reads those config values and builds a single
    // dangerous_goods entry. That entry is then attached to every package
    // position on the shipment.
    // -------------------------------------------------------------------------

    /**
     * Build a single dangerous_goods entry from the plugin config's
     * "Gefahrgut" tab. Returns an empty array if the mandatory UN number
     * is not configured (safer than sending a broken block to Zufall).
     *
     * @return array Single dangerous_goods entry or empty array.
     */
    public function buildDangerousGoodsFromConfig(): array
    {
        $settings = $this->settingsService->getSettings();

        // Mandatory check: un_number must be set. If the customer has not
        // filled in the config yet, we refuse to build a broken hazmat block
        // (would cause HTTP 500 at Zufall).
        $unNumber = trim((string) ($settings['hazmat_un_number'] ?? ''));
        if ($unNumber === '') {
            return [];
        }

        $entry = [
            'release'                 => (string) ($settings['hazmat_release'] ?? '2025'),
            'package_quantity'        => (int) ($settings['hazmat_package_quantity'] ?? 1),
            'weight'                  => (int) ($settings['hazmat_weight_gr'] ?? 0),
            'un_number'               => $unNumber,
            'packaging_description'   => substr((string) ($settings['hazmat_packaging_description'] ?? ''), 0, 35),
            'multiplicator'           => (int) ($settings['hazmat_multiplicator'] ?? 1),
            'name'                    => substr((string) ($settings['hazmat_name'] ?? ''), 0, 210),
            'main_danger'             => (string) ($settings['hazmat_main_danger'] ?? ''),
            'tunnel_restriction_code' => (string) ($settings['hazmat_tunnel_restriction_code'] ?? 'E'),
        ];

        // Optional fields – only include when non-empty so we don't clutter
        // the payload with empty strings Zufall might reject.
        $optionalStringFields = [
            'packaging_group'       => 'hazmat_packaging_group',
            'packaging_group_class' => 'hazmat_packaging_group_class',
            'classification_code'   => 'hazmat_classification_code',
        ];
        foreach ($optionalStringFields as $payloadKey => $configKey) {
            $val = trim((string) ($settings[$configKey] ?? ''));
            if ($val !== '') {
                $entry[$payloadKey] = $val;
            }
        }

        // Boolean flags from dropdowns (config stores as "0"/"1" strings)
        $optionalBoolFields = [
            'is_lq'                          => 'hazmat_is_lq',
            'is_exempt'                      => 'hazmat_is_exempt',
            'is_hazardous_to_the_environment' => 'hazmat_is_hazardous_to_the_environment',
        ];
        foreach ($optionalBoolFields as $payloadKey => $configKey) {
            if (($settings[$configKey] ?? '0') === '1') {
                $entry[$payloadKey] = true;
            }
        }

        return $entry;
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
            'shipping_value' => $this->getOrderValue($order),
            'value_currency' => $this->getOrderCurrency($order),
            'weight_gr' => $this->calculateTotalWeightGram($packages),
            'options' => $options,
            'positions' => $this->buildPositions($packages, $order),
            'texts' => $this->buildTexts($order),
        ];
    }

    // -------------------------------------------------------------------------
    // Bordero payload
    // -------------------------------------------------------------------------

    public function buildBorderoPayload(array $shipments, string $pickupDate, string $listId): array
    {
        $settings = $this->settingsService->getSettings();

        // Sendungen nach Familie gruppieren.
        $families = [];
        foreach ($shipments as $shipment) {
            $parentId = (int)($shipment['parent_order_id'] ?? 0);
            $orderId  = (int)($shipment['order_id'] ?? 0);
            $familyId = $parentId > 0 ? $parentId : $orderId;
            $families[$familyId][] = $shipment;
        }

        $shippingObjects = [];
        foreach ($families as $familyId => $familyShipments) {
            if (count($familyShipments) === 1) {
                $shippingObjects[] = $this->buildShippingObjectFromStoredData(
                    $familyShipments[0], $pickupDate
                );
            } else {
                $shippingObjects[] = $this->buildFamilyShippingObject(
                    $familyShipments, $pickupDate, $familyId
                );
            }
        }

        return [
            'customer_id' => $settings['plenty_customer_id_at_transland'],
            'branch' => 'TRANSL1',
            'list_id' => $listId,
            'pickup_date' => $pickupDate,
            'shippings' => $shippingObjects,
        ];
    }

    private function buildFamilyShippingObject(array $familyShipments, string $pickupDate, int $familyId): array
    {
        $first = $familyShipments[0];

        $allPositions  = [];
        $totalWeightGr = 0;
        $totalValue    = 0.0;
        $allOptions    = [];
        $allTexts      = [];

        foreach ($familyShipments as $shipment) {
            foreach (($shipment['packages'] ?? []) as $pos) {
                $allPositions[] = $pos;
            }
            $totalWeightGr += (int)($shipment['weight_gr'] ?? 0);
            $totalValue    += (float)($shipment['value'] ?? 0);

            if (!empty($shipment['options']) && is_array($shipment['options'])) {
                foreach ($shipment['options'] as $opt) {
                    $code = (string)($opt['code'] ?? '');
                    if (!empty($code) && is_numeric($code) && (int)$code > 0) {
                        $allOptions[$code] = $opt;
                    }
                }
            }

            if (!empty($shipment['texts']) && is_array($shipment['texts'])) {
                foreach ($shipment['texts'] as $txt) {
                    if (!empty($txt) && is_string($txt)) {
                        $allTexts[] = $txt;
                    }
                }
            }
        }

        $obj = [
            'shipper_address'  => $first['shipper_address'] ?? [],
            'consignee_address' => $first['consignee_address'] ?? [],
            'loading_address'  => $first['loading_address'] ?? $first['shipper_address'] ?? [],
            'pickup_date'      => $pickupDate,
            'procurement'      => $first['procurement'] ?? false,
            'franking'         => $first['franking'] ?? '1',
            'reference'        => (string)$familyId,
            'shipping_value'   => round($totalValue, 2),
            'value_currency'   => $first['value_currency'] ?? 'EUR',
            'weight_gr'        => $totalWeightGr,
            'positions'        => $allPositions,
        ];

        $cleanOptions = [];
        foreach ($allOptions as $opt) {
            $code = (string)($opt['code'] ?? '');
            if (!empty($code)) {
                $entry = ['code' => (int)$code];
                if (!empty($opt['text'])) {
                    $entry['text'] = substr((string)$opt['text'], 0, 35);
                }
                $cleanOptions[] = $entry;
            }
        }
        if (!empty($cleanOptions)) {
            $obj['options'] = $cleanOptions;
        }

        $uniqueTexts = array_values(array_unique($allTexts));
        if (!empty($uniqueTexts)) {
            $obj['texts'] = array_slice($uniqueTexts, 0, 6);
        }

        return $obj;
    }

    private function buildShippingObjectFromStoredData(array $shipment, string $pickupDate): array
    {
        $obj = [
            'shipper_address' => $shipment['shipper_address'] ?? [],
            'consignee_address' => $shipment['consignee_address'] ?? [],
            'loading_address' => $shipment['loading_address'] ?? $shipment['shipper_address'] ?? [],
            'pickup_date' => $pickupDate,
            'procurement' => $shipment['procurement'] ?? false,
            'franking' => $shipment['franking'] ?? '1',
            'reference' => $shipment['reference'] ?? '',
            'shipping_value' => round((float) ($shipment['value'] ?? 0), 2),
            'value_currency' => $shipment['value_currency'] ?? 'EUR',
            'weight_gr' => (int) ($shipment['weight_gr'] ?? 0),
            'positions' => $shipment['packages'] ?? [],
        ];

        if (!empty($shipment['options']) && is_array($shipment['options'])) {
            $cleanOptions = [];
            foreach ($shipment['options'] as $opt) {
                $code = (string) ($opt['code'] ?? '');
                if (!empty($code) && is_numeric($code) && (int) $code > 0) {
                    $entry = ['code' => (int) $code];
                    if (!empty($opt['text'])) {
                        $entry['text'] = substr((string) $opt['text'], 0, 35);
                    }
                    $cleanOptions[] = $entry;
                }
            }
            if (!empty($cleanOptions)) {
                $obj['options'] = $cleanOptions;
            }
        }

        if (!empty($shipment['texts']) && is_array($shipment['texts'])) {
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
    public function buildPositions(array $packages, array $order = []): array
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

            // packages — array of NVE/SSCC barcodes belonging to this position.
            // Per Zufall V2 spec (Seite 13): [{"sscc": "003..."}]
            // Die SSCC kommt vom Label-Endpoint zurück und wird über
            // mergeSSCCsIntoPackages in die Position geschrieben.
            $ssccEntries = [];
            if (!empty($pkg['ssccs']) && is_array($pkg['ssccs'])) {
                foreach ($pkg['ssccs'] as $s) {
                    if (!empty($s)) {
                        $ssccEntries[] = ['sscc' => (string) $s];
                    }
                }
            } elseif (!empty($pkg['sscc'])) {
                $ssccEntries[] = ['sscc' => (string) $pkg['sscc']];
            }
            $built['packages'] = $ssccEntries;

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
        $ref = !empty($order['externalOrderId']) ? $order['externalOrderId'] : (string) ($order['id'] ?? '');

        // 101: Telefonavisierung — IMMER setzen (pauschal pro Kundenanforderung)
        $phone = $delivery['phone'] ?? '';
        if (empty($phone)) {
            $phone = $delivery['name1'] ?? 'siehe Lieferschein';
        }
        $options[] = ['code' => 101, 'text' => substr((string)$phone, 0, 35)];

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

    private function getOrderValue(array $order): float
    {
        // Warenwert NETTO verwenden (für Zufall Versicherungswert).
        // Plenty OrderAmount:
        //   netTotal     = Warenwert netto (nur Waren, ohne Versand/MwSt)
        //   invoiceTotal = Rechnungsbetrag (inkl. Versand, MwSt)
        //
        // isNet=true → Netto-Eintrag, isNet=false → Brutto-Eintrag
        // Wir nehmen netTotal vom Netto-Eintrag = "Warenwert netto" aus Plenty.
        foreach (($order['amounts'] ?? []) as $amount) {
            if (($amount['isNet'] ?? false) === true) {
                $val = round((float)($amount['netTotal'] ?? 0), 2);
                if ($val > 0) {
                    return $val;
                }
            }
        }
        // Fallback 1: netTotal vom Brutto-Eintrag
        foreach (($order['amounts'] ?? []) as $amount) {
            $val = round((float)($amount['netTotal'] ?? 0), 2);
            if ($val > 0) {
                return $val;
            }
        }
        // Fallback 2: invoiceTotal (besser als 0)
        foreach (($order['amounts'] ?? []) as $amount) {
            $val = round((float)($amount['invoiceTotal'] ?? 0), 2);
            if ($val > 0) {
                return $val;
            }
        }
        return 0.0;
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