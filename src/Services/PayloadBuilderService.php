<?php

namespace TranslandShipping\Services;

/**
 * PayloadBuilderService
 *
 * Maps PlentyMarkets order/shipment data structures to
 * the Zufall/Transland API JSON format.
 *
 * Packaging type mapping (Plenty → Zufall):
 *   The packing process IDs 52, 73, 79, 85, 87 each may use
 *   different default packaging types, configurable in settings.
 */
class PayloadBuilderService
{
    // Default packaging type map: can be overridden in plugin settings
    private const PACKAGING_TYPE_MAP = [
        'europalette'          => 'FP',
        'einwegpalette'        => 'EP',
        'chep-palette'         => 'CP',
        'chep-halbpalette'     => 'CH',
        'chep-viertelpalette'  => 'CV',
        'duesseldorf-palette'  => 'DP',
        'halbpalette'          => 'HP',
        'viertelpalette'       => 'VP',
        'gitterboxpalette'     => 'GP',
        'kundeneigene-palette' => 'KP',
        'karton'               => 'KT',
        'paket'                => 'PA',
        'pack'                 => 'PK',
        'ballen'               => 'BL',
        'bund'                 => 'BU',
        'fass'                 => 'FA',
        'eimer'                => 'EI',
        'kiste'                => 'KI',
        'sack'                 => 'SA',
        'stueck'               => 'ST',
        'sonderbehälter'       => 'KB',
    ];

    // Franking: 1 = frei Haus, 2 = unfrei
    private const FRANKING_FREI_HAUS = '1';
    private const FRANKING_UNFREI    = '2';

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    // -------------------------------------------------------------------------
    // Label payload
    // -------------------------------------------------------------------------

    /**
     * Build a Shipping object payload for the label endpoint.
     * Called during the packing process in Plenty.
     *
     * @param array $order        PlentyMarkets order data
     * @param array $packages     Array of package data from packing process
     * @param array $options      Optional: sendungs-optionen (e.g. avisierung)
     *
     * @return array  Ready-to-POST Shipping object
     */
    public function buildLabelPayload(array $order, array $packages, array $options = []): array
    {
        $settings = $this->settingsService->getSettings();

        return [
            'shipper_address'    => $this->buildShipperAddress($settings),
            'consignee_address'  => $this->buildConsigneeAddress($order),
            'pickup_date'        => date('Y-m-d'), // required for label endpoint
            'franking'           => self::FRANKING_FREI_HAUS,
            'reference'          => $this->buildReference($order),
            'value'              => $this->getOrderValue($order),
            'value_currency'     => $this->getOrderCurrency($order),
            'weight_gr'          => $this->calculateTotalWeightGram($packages),
            'options'            => $options,
            'packages'           => $this->buildPackages($packages),
            'texts'              => $this->buildTexts($order),
        ];
    }

    // -------------------------------------------------------------------------
    // Shipping list (Bordero) payload
    // -------------------------------------------------------------------------

    /**
     * Build a full Versandliste (Bordero) payload for the shipping-list endpoint.
     * Called at day-close / Tagesabschluss.
     *
     * @param array  $shipments   Array of shipment data (with SSCCs already set)
     * @param string $pickupDate  Date in format YYYY-MM-DD
     * @param string $listId      Unique list ID (e.g. date + sequence number)
     *
     * @return array  Ready-to-POST Versandliste object
     */
    public function buildBorderoPayload(array $shipments, string $pickupDate, string $listId): array
    {
        $settings = $this->settingsService->getSettings();

        return [
            'customer_id' => $settings['plenty_customer_id_at_transland'],
            'branch'      => 'TRANSL1',  // Transland Haiger - fixed per documentation
            'list_id'     => $listId,
            'pickup_date' => $pickupDate,
            'shippings'   => array_map(
                fn(array $shipment) => $this->buildShippingObjectFromStoredData($shipment),
                $shipments
            ),
        ];
    }

    /**
     * Build a single Shipping object from data stored in Plenty
     * (i.e. after the label was already printed and SSCC was saved).
     */
    public function buildShippingObjectFromStoredData(array $shipment): array
    {
        return [
            'shipper_address'   => $shipment['shipper_address'],
            'consignee_address' => $shipment['consignee_address'],
            'franking'          => $shipment['franking'] ?? self::FRANKING_FREI_HAUS,
            'reference'         => $shipment['reference'],
            'value'             => $shipment['value'],
            'value_currency'    => $shipment['value_currency'] ?? 'EUR',
            'weight_gr'         => $shipment['weight_gr'],
            'options'           => $shipment['options'] ?? [],
            'packages'          => $shipment['packages'], // Must include SSCC!
            'texts'             => $shipment['texts'] ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Address builders
    // -------------------------------------------------------------------------

    /**
     * Build shipper address from plugin settings (your warehouse/company).
     */
    public function buildShipperAddress(array $settings): array
    {
        return [
            'name1'          => $settings['shipper_name1'] ?? '',
            'name2'          => $settings['shipper_name2'] ?? '',
            'street'         => $settings['shipper_street'] ?? '',
            'country'        => $settings['shipper_country'] ?? 'DE',
            'zip'            => $settings['shipper_zip'] ?? '',
            'city'           => $settings['shipper_city'] ?? '',
            'phone'          => $settings['shipper_phone'] ?? '',
            'email'          => $settings['shipper_email'] ?? '',
            'contact_person' => $settings['shipper_contact'] ?? '',
        ];
    }

    /**
     * Build consignee address from PlentyMarkets order delivery address.
     */
    public function buildConsigneeAddress(array $order): array
    {
        // PlentyMarkets delivery address structure
        $delivery = $order['deliveryAddress'] ?? $order['billingAddress'] ?? [];

        $name1 = trim(($delivery['company'] ?? '') ?: implode(' ', array_filter([
            $delivery['firstName'] ?? '',
            $delivery['lastName'] ?? '',
        ])));

        $name2 = '';
        if (!empty($delivery['company'])) {
            $name2 = trim(($delivery['firstName'] ?? '') . ' ' . ($delivery['lastName'] ?? ''));
        }

        return array_filter([
            'name1'          => substr($name1, 0, 35),
            'name2'          => substr($name2, 0, 35),
            'street'         => substr(
                ($delivery['address1'] ?? '') . ' ' . ($delivery['address2'] ?? ''),
                0, 35
            ),
            'country'        => $delivery['countryId'] ? $this->mapCountryId($delivery['countryId']) : 'DE',
            'zip'            => substr($delivery['postalCode'] ?? '', 0, 9),
            'city'           => substr($delivery['town'] ?? '', 0, 35),
            'contact_person' => substr(
                ($delivery['firstName'] ?? '') . ' ' . ($delivery['lastName'] ?? ''),
                0, 256
            ),
            'phone'          => substr($delivery['phone'] ?? '', 0, 256),
            'email'          => substr($delivery['email'] ?? '', 0, 256),
        ]);
    }

    // -------------------------------------------------------------------------
    // Package builders
    // -------------------------------------------------------------------------

    /**
     * Build packages array from Plenty packing process data.
     * Note: sscc is left empty here - Transland assigns it and returns it in the label response.
     *
     * @param array $packages  Packages from Plenty packing process
     * @return array
     */
    public function buildPackages(array $packages): array
    {
        return array_map(function (array $pkg) {
            $built = [
                'content'          => substr($pkg['content'] ?? 'Waren', 0, 70),
                'packaging_type'   => $this->mapPackagingType($pkg['packaging_type'] ?? 'FP'),
                'length_cm'        => (int)($pkg['length_cm'] ?? 0),
                'width_cm'         => (int)($pkg['width_cm'] ?? 0),
                'height_cm'        => (int)($pkg['height_cm'] ?? 0),
                'weight_gr'        => (int)($pkg['weight_gr'] ?? 0),
            ];

            // Optional fields - only include if set
            if (!empty($pkg['sscc'])) {
                $built['sscc'] = $pkg['sscc'];
            }
            if (!empty($pkg['reference'])) {
                $built['reference'] = substr($pkg['reference'], 0, 35);
            }
            if (!empty($pkg['sub_packaging_count'])) {
                $built['sub_packaging_count'] = (int)$pkg['sub_packaging_count'];
                $built['sub_packaging_type']  = $this->mapPackagingType($pkg['sub_packaging_type'] ?? 'KT');
            }

            return $built;
        }, $packages);
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function buildReference(array $order): string
    {
        // Use order ID + external order number if available
        $parts = array_filter([
            isset($order['id']) ? 'ORD-' . $order['id'] : null,
            $order['externalOrderId'] ?? null,
        ]);
        return implode(' / ', $parts);
    }

    private function getOrderValue(array $order): int
    {
        // PlentyMarkets stores order amounts in the amounts array
        foreach (($order['amounts'] ?? []) as $amount) {
            if (($amount['isNet'] ?? false) === false) {
                return (int)round(($amount['invoiceTotal'] ?? 0) * 100);
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
        return (int)array_sum(array_column($packages, 'weight_gr'));
    }

    private function buildTexts(array $order): array
    {
        $texts = [];
        if (!empty($order['notes'])) {
            // Chunk notes into max 70 char segments as required by API
            $chunks = str_split($order['notes'], 70);
            $texts  = array_slice($chunks, 0, 5); // reasonable limit
        }
        return $texts;
    }

    /**
     * Map a packaging type string to Zufall API code.
     * Accepts either the full name or already-short code.
     */
    private function mapPackagingType(string $type): string
    {
        $type  = strtolower(trim($type));
        $upper = strtoupper($type);

        // Already a valid 2-letter code?
        $validCodes = ['BL','BU','CH','CP','CV','DP','EI','EP','FA','FP','GP','HP','KB','KI','KP','KT','PA','PK','SA','ST','VP'];
        if (in_array($upper, $validCodes, true)) {
            return $upper;
        }

        return self::PACKAGING_TYPE_MAP[$type] ?? 'FP'; // Default: Europalette
    }

    /**
     * Map PlentyMarkets country ID to ISO 3166-1 alpha-2 code.
     * This is a partial mapping - extend as needed.
     */
    private function mapCountryId(int $countryId): string
    {
        $map = [
            1  => 'DE', // Germany
            2  => 'AT', // Austria
            4  => 'CH', // Switzerland
            5  => 'CY', // Cyprus
            6  => 'CZ', // Czech Republic
            7  => 'DK', // Denmark
            8  => 'ES', // Spain
            9  => 'EE', // Estonia
            10 => 'FR', // France
            11 => 'FI', // Finland
            12 => 'BE', // Belgium
            13 => 'GR', // Greece
            14 => 'GB', // United Kingdom
            15 => 'IE', // Ireland
            17 => 'IT', // Italy
            18 => 'LV', // Latvia
            19 => 'LT', // Lithuania
            20 => 'LU', // Luxembourg
            21 => 'MT', // Malta
            22 => 'NL', // Netherlands
            23 => 'PL', // Poland
            24 => 'PT', // Portugal
            25 => 'RO', // Romania
            26 => 'SE', // Sweden
            27 => 'SK', // Slovakia
            28 => 'SI', // Slovenia
            29 => 'HU', // Hungary
            34 => 'US', // USA
            35 => 'CA', // Canada
            36 => 'AU', // Australia
            66 => 'NO', // Norway
            74 => 'TR', // Turkey
        ];

        return $map[$countryId] ?? 'DE';
    }
}
