<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\Log\Loggable;

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

        if (empty($recipient)) return;

        // Base64 bereinigen (Präfix entfernen, falls vorhanden)
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename = 'label-auftrag-' . $orderId . '.' . $extension;
        $mimeType = ($extension === 'pdf') ? 'application/pdf' : 'text/plain';

        // Berechnung der Größe in KB für den Validator
        $decodedData = base64_decode($labelBase64);
        $sizeInKb = (int)ceil(strlen($decodedData) / 1024);

        try {
            $mailData = [
                "account" => [
                    "type" => "messenger_inbox", 
                    "id"   => (int)($settings['messenger_id'] ?? 1),
                    "name" => "Transland Messenger", // Pflichtfeld laut deiner Liste
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
                        "size"        => $sizeInKb, // Jetzt explizit in KB
                        "contentType" => $mimeType
                    ]
                ]
            ];

            // Versand-Log
            $this->getLogger(__CLASS__)->info('TranslandShipping::mail.attempt', ['orderId' => $orderId]);

            $this->emailSendService->sendPreview($mailData);

        } catch (\Throwable $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::mail.error', [
                'message' => $e->getMessage(),
                'orderId' => $orderId
            ]);
        }
    }
}