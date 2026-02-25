<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;

/**
 * Class EmailService
 * Versendet Emails nun über den Messenger-Kanal 1.
 */
class EmailService
{
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
                // Umstellung auf den Messenger-Kanal
                "account" => [
                    "type" => "messenger_inbox", 
                    "id"   => 1, // Dein neuer Messenger-Kanal
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
                        "size"        => (int)(strlen(base64_decode($labelBase64)) / 1024),
                        "contentType" => $mimeType
                    ]
                ]
            ];

            // Versand über die Preview-Schnittstelle (erzeugt den Entwurf/Versand im Messenger)
            $this->emailSendService->sendPreview($mailData);

        } catch (\Throwable $e) {
            // Keine Logs wie gewünscht
        }
    }
}