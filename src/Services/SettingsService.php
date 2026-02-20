<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Plugin\DataBase\Contracts\DataBaseContract;
use Plenty\Plugin\Log\Loggable;

/**
 * SettingsService
 *
 * Manages plugin settings storage and retrieval.
 * Settings are stored in the plugin database.
 */
class SettingsService
{
    use Loggable;

    private DataBaseContract $database;

    // Setting keys with their defaults
    private const DEFAULTS = [
        // API connection
        'sandbox'                         => true,
        'api_customer_id'                 => '',      // [KUNDE] in URL - from Hr. Schrader
        'username'                        => '',      // DigestAuth username
        'password'                        => '',      // DigestAuth password
        'plenty_customer_id_at_transland' => '',      // Kundennummer at Zufall (customer_id in Bordero)

        // Label format
        'label_format'                    => 'PDF',   // PDF or ZPL

        // Shipper (your company) address - used in every shipment
        'shipper_name1'                   => '',
        'shipper_name2'                   => '',
        'shipper_street'                  => '',
        'shipper_zip'                     => '',
        'shipper_city'                    => '',
        'shipper_country'                 => 'DE',
        'shipper_phone'                   => '',
        'shipper_email'                   => '',
        'shipper_contact'                 => '',

        // Bordero / Tagesabschluss
        'auto_submit_enabled'             => true,
        'auto_submit_time'                => '17:00', // Daily cron submission time
        'return_ladeliste_pdf'            => true,    // Request Ladeliste PDF from Transland

        // Packing process defaults (process IDs: 52, 73, 79, 85, 87)
        'default_packaging_type'          => 'FP',   // Default: Europalette
        'default_franking'                => '1',    // 1 = frei Haus

        // Packing process specific packaging types
        'packaging_type_process_52'       => 'KT',   // Kleinteile/Sonst. Teile → Karton
        'packaging_type_process_73'       => 'FP',   // Montageschienen → Europalette
        'packaging_type_process_79'       => 'KT',   // Kleinteile/Sonst. Teile → Karton
        'packaging_type_process_85'       => 'FP',   // PV Module → Europalette
        'packaging_type_process_87'       => 'KT',   // Elektro → Karton
    ];

    public function __construct(DataBaseContract $database)
    {
        $this->database = $database;
    }

    /**
     * Get all settings, merged with defaults.
     */
    public function getSettings(): array
    {
        $records = $this->database->query(\TranslandShipping\Models\TranslandSetting::class)
            ->get();

        $stored = [];
        foreach ($records as $record) {
            $stored[$record->settingKey] = $record->settingValue;
        }

        return array_merge(self::DEFAULTS, $stored);
    }

    /**
     * Save settings (upsert each key).
     *
     * @param array $settings Key-value pairs to save
     */
    public function saveSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue; // Ignore unknown keys
            }

            $existing = $this->database->query(\TranslandShipping\Models\TranslandSetting::class)
                ->where('settingKey', '=', $key)
                ->get();

            if (!empty($existing)) {
                $record = $existing[0];
            } else {
                $record = pluginApp(\TranslandShipping\Models\TranslandSetting::class);
                $record->settingKey = $key;
            }

            $record->settingValue = (string)$value;
            $record->updatedAt    = date('Y-m-d H:i:s');
            $this->database->save($record);
        }
    }

    /**
     * Get the default packaging type for a specific packing process ID.
     *
     * @param int $processId  Plenty packing process ID (52, 73, 79, 85, 87)
     */
    public function getPackagingTypeForProcess(int $processId): string
    {
        $settings = $this->getSettings();
        $key      = 'packaging_type_process_' . $processId;
        return $settings[$key] ?? $settings['default_packaging_type'] ?? 'FP';
    }
}
