<?php

namespace TranslandShipping\Services;

// Die exakten Namespaces für Stable 7 / PlentyONE
use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\Log\Loggable;

/**
 * Class EmailService
 * @package TranslandShipping\Services
 */
class EmailService
{
    use Loggable;

    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * @var EmailTemplatesSendServiceContract
     */
    private $emailSendService;

    /**
     * EmailService constructor.
     * @param SettingsService $settingsService
     * @param EmailTemplatesSendServiceContract $emailSendService
     */
    public function __construct(
        SettingsService $settingsService,
        EmailTemplatesSendServiceContract $emailSendService
    ) {
        $this->settingsService = $settingsService;
        $this->emailSendService = $emailSendService;
    }

    /**
     * Sendet das Versandlabel per E-Mail unter Nutzung des Stable 7 Email-Builders.
     *
     * @param string $labelBase64
     * @param int $orderId
     * @param string $format
     */
    public function sendLabelEmail(string $labelBase64, int $orderId, string $format = 'PDF'): void
    {
        $settings = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        // Validierung des Empfängers
        if (empty($recipient)) {
            $this->getLogger(__METHOD__)->warning('TranslandShipping::email.no_recipient');
            return;
        }

        // Base64-String bereinigen, falls ein Data-Header mitgeschickt wurde
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename = 'label-auftrag-' . $orderId . '.' . $extension;
        $mimeType = ($extension === 'pdf') ? 'application/pdf' : 'text/plain';

        try {
            /**
             * In Stable 7 erwartet sendPreview ein Array, das der REST-API Struktur entspricht.
             */
            $mailData = [
                "from"      => $settings['sender_email'] ?? '', // Empfohlen: Setze eine valide Absender-Adresse
                "subject"   => 'Transland Label - Auftrag ' . $orderId,
                "body"      => '<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>',
                "receivers" => [
                    "to" => [
                        [
                            "email" => $recipient,
                            "name"  => "Transland Logistik"
                        ]
                    ]
                ],
                "attachments" => [
                    [
                        "content" => $labelBase64, // Base64 kodierter Inhalt
                        "name"    => $filename,
                        "type"    => $mimeType
                    ]
                ]
            ];

            // Optionale Account-ID hinzufügen, falls in Settings hinterlegt
            if (isset($settings['mail_account_id']) && (int)$settings['mail_account_id'] > 0) {
                $mailData['accountId'] = (int)$settings['mail_account_id'];
            }

            // Der eigentliche Versand über den neuen Contract
            $this->emailSendService->sendPreview($mailData);

            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', [
                'orderId'   => $orderId,
                'recipient' => $recipient
            ]);

        } catch (\Throwable $e) {
            // Detailliertes Logging für den "validation error"
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'message' => $e->getMessage(),
                'orderId' => $orderId,
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ]);
        }
    }
}