<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

class SettingsService
{
    use Loggable;

    private ConfigRepository $config;

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    public function getSettings(): array
    {
        $settings = [
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
            'packaging_type_process_52'       => $this->config->get('TranslandShipping.packaging_type_process_52', 'KT'),
            'packaging_type_process_73'       => $this->config->get('TranslandShipping.packaging_type_process_73', 'FP'),
            'packaging_type_process_79'       => $this->config->get('TranslandShipping.packaging_type_process_79', 'KT'),
            'packaging_type_process_85'       => $this->config->get('TranslandShipping.packaging_type_process_85', 'FP'),
            'packaging_type_process_87'       => $this->config->get('TranslandShipping.packaging_type_process_87', 'KT'),
        ];

        // DIAGNOSE: Zeigt ob die Konfiguration im Backend befüllt ist
        $this->getLogger(__METHOD__)->error('TranslandShipping::settings.loaded', [
            'shipper_name1'  => $settings['shipper_name1']  ?: 'LEER – bitte im Backend befüllen!',
            'shipper_street' => $settings['shipper_street'] ?: 'LEER',
            'shipper_zip'    => $settings['shipper_zip']    ?: 'LEER',
            'shipper_city'   => $settings['shipper_city']   ?: 'LEER',
            'api_customer_id'=> $settings['api_customer_id'],
            'sandbox'        => $settings['sandbox'],
        ]);

        return $settings;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->config->get('TranslandShipping.' . $key, $default);
    }

    public function getPackagingTypeForProcess(int $processId): string
    {
        return $this->config->get('TranslandShipping.packaging_type_process_' . $processId, 'FP');
    }
}