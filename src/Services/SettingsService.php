<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\ConfigRepository;

/**
 * SettingsService
 *
 * Reads plugin configuration via PlentyONE's official ConfigRepository.
 * Settings are managed through config.json and editable in the
 * Plenty backend: Plugins → Plugin-Set → TranslandShipping → Konfiguration
 */
class SettingsService
{
    private ConfigRepository $config;

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    /**
     * Get all settings as array.
     */
    public function getSettings(): array
    {
        return [
            'sandbox'                         => $this->config->get('TranslandShipping.sandbox', '1'),
            'api_customer_id'                 => $this->config->get('TranslandShipping.api_customer_id', 'venturama'),
            'plenty_customer_id_at_transland' => $this->config->get('TranslandShipping.plenty_customer_id_at_transland', ''),
            'username'                        => $this->config->get('TranslandShipping.username', ''),
            'password'                        => $this->config->get('TranslandShipping.password', ''),
            'shipper_name1'                   => $this->config->get('TranslandShipping.shipper_name1', ''),
            'shipper_name2'                   => $this->config->get('TranslandShipping.shipper_name2', ''),
            'shipper_street'                  => $this->config->get('TranslandShipping.shipper_street', ''),
            'shipper_zip'                     => $this->config->get('TranslandShipping.shipper_zip', ''),
            'shipper_city'                    => $this->config->get('TranslandShipping.shipper_city', ''),
            'shipper_country'                 => $this->config->get('TranslandShipping.shipper_country', 'DE'),
            'shipper_phone'                   => $this->config->get('TranslandShipping.shipper_phone', ''),
            'shipper_contact'                 => $this->config->get('TranslandShipping.shipper_contact', ''),
            'shipper_email'                   => $this->config->get('TranslandShipping.shipper_email', ''),
            'label_format'                    => $this->config->get('TranslandShipping.label_format', 'PDF'),
            'auto_submit_enabled'             => $this->config->get('TranslandShipping.auto_submit_enabled', '1'),
            'auto_submit_time'                => $this->config->get('TranslandShipping.auto_submit_time', '17:00'),
            'return_ladeliste_pdf'            => $this->config->get('TranslandShipping.return_ladeliste_pdf', '1'),
            'label_email'                     => $this->config->get('TranslandShipping.label_email', ''),
            'packaging_type_process_52'       => $this->config->get('TranslandShipping.packaging_type_process_52', 'KT'),
            'packaging_type_process_73'       => $this->config->get('TranslandShipping.packaging_type_process_73', 'FP'),
            'packaging_type_process_79'       => $this->config->get('TranslandShipping.packaging_type_process_79', 'KT'),
            'packaging_type_process_85'       => $this->config->get('TranslandShipping.packaging_type_process_85', 'FP'),
            'packaging_type_process_87'       => $this->config->get('TranslandShipping.packaging_type_process_87', 'KT'),

            // Gefahrgut / dangerous_goods profile (venturama single-hazmat model)
            // Consumed by PayloadBuilderService::buildDangerousGoodsFromConfig()
            // only when an order has the Gefahrenstoff tag. If hazmat_un_number
            // is empty, the whole dangerous_goods block is skipped.
            'hazmat_release'                         => $this->config->get('TranslandShipping.hazmat_release', '2025'),
            'hazmat_un_number'                       => $this->config->get('TranslandShipping.hazmat_un_number', ''),
            'hazmat_name'                            => $this->config->get('TranslandShipping.hazmat_name', ''),
            'hazmat_main_danger'                     => $this->config->get('TranslandShipping.hazmat_main_danger', ''),
            'hazmat_tunnel_restriction_code'         => $this->config->get('TranslandShipping.hazmat_tunnel_restriction_code', 'E'),
            'hazmat_packaging_description'           => $this->config->get('TranslandShipping.hazmat_packaging_description', ''),
            'hazmat_package_quantity'                => $this->config->get('TranslandShipping.hazmat_package_quantity', '1'),
            'hazmat_weight_gr'                       => $this->config->get('TranslandShipping.hazmat_weight_gr', '0'),
            'hazmat_multiplicator'                   => $this->config->get('TranslandShipping.hazmat_multiplicator', '1'),
            'hazmat_packaging_group'                 => $this->config->get('TranslandShipping.hazmat_packaging_group', ''),
            'hazmat_packaging_group_class'           => $this->config->get('TranslandShipping.hazmat_packaging_group_class', ''),
            'hazmat_classification_code'             => $this->config->get('TranslandShipping.hazmat_classification_code', ''),
            'hazmat_is_lq'                           => $this->config->get('TranslandShipping.hazmat_is_lq', '0'),
            'hazmat_is_exempt'                       => $this->config->get('TranslandShipping.hazmat_is_exempt', '0'),
            'hazmat_is_hazardous_to_the_environment' => $this->config->get('TranslandShipping.hazmat_is_hazardous_to_the_environment', '0'),
        ];
    }

    /**
     * Get a single setting value.
     */
    public function get(string $key, string $default = ''): string
    {
        return $this->config->get('TranslandShipping.' . $key, $default);
    }

    /**
     * Get the default packaging type for a specific packing process ID.
     */
    public function getPackagingTypeForProcess(int $processId): string
    {
        return $this->config->get('TranslandShipping.packaging_type_process_' . $processId, 'FP');
    }
}