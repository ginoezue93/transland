<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\Log\Loggable;

/**
 * Class EmailService
 * Versendet Emails über den Messenger-Kanal und loggt den Status.
 */
class EmailService
{
    use Loggable;

    private $settingsService;
    private $emailSendService;

    public function __construct(
        SettingsService $settingsService,
        EmailTemplatesSendServiceContract $emailSendService
    ) {
        $this->settingsService = $settingsService;
        $this->emailSendService = $emailSendService;
    }

    public function sendLabelEmail(string $labelBase64, int $orderId, string $format = 'PDF'): void
    {
        $settings = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        if (empty($recipient)) {
            $this->getLogger(__CLASS__)->warning('TranslandShipping::mail.noRecipient', [
                'orderId' => $orderId
            ]);
            return;
        }

        // Base64 bereinigen
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename = 'label-auftrag-' . $orderId . '.' . $extension;
        $mimeType = ($extension === 'pdf') ? 'application/pdf' : 'text/plain';

        try {
            $mailData = [
                "account" => [
                    "type" => "messenger_inbox", 
                    "id"   => (int)($settings['messenger_id'] ?? 1), 
                    "from" => [
                        "name"    => "Transland Logistik",
                        "address" => $settings['sender_email'] ?? ''
                    ]
                ],
                "to" => [
                    [
                        "name"    => "Versandabteilung",
                        "address" => $recipient
                    ]
                ],
                "subject" => 'Transland Label - Auftrag ' . $orderId,
                "body"    => 'Anbei das Versandlabel für Auftrag ' . $orderId . '.',
                "attachments" => [
                    [
                        "name"        => $filename,
                        "body"        => $labelBase64, 
                        "size"        => strlen(base64_decode($labelBase64)), // Größe in Bytes
                        "contentType" => $mimeType
                    ]
                ]
            ];

            $this->getLogger(__CLASS__)->info('TranslandShipping::mail.sending', [
                'orderId'   => $orderId,
                'recipient' => $recipient,
                'messengerId' => $mailData['account']['id']
            ]);

            $this->emailSendService->sendPreview($mailData);

            $this->getLogger(__CLASS__)->info('TranslandShipping::mail.success', [
                'orderId' => $orderId
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::mail.error', [
                'orderId' => $orderId,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);
        }
    }
}