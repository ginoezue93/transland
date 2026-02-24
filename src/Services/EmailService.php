<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\Log\Loggable;

/**
 * Class EmailService
 * Struktur angepasst an die REST-Spezifikation für sendPreview
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
            $this->getLogger(__METHOD__)->warning('TranslandShipping::email.no_recipient');
            return;
        }

        // Base64 bereinigen (Header entfernen falls vorhanden)
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename = 'label-auftrag-' . $orderId . '.' . $extension;
        $mimeType = ($extension === 'pdf') ? 'application/pdf' : 'text/plain';

        try {
            /**
             * Mapping basierend auf deiner bereitgestellten Struktur:
             */
            $mailData = [
                // 'account' Mapping
                "account" => [
                    "type" => "webstore", // oder messenger_inbox
                    "id"   =>  1,
                    "from" => [
                        "name"    => "Transland Logistik",
                        "address" => $settings['sender_email'] ?? ''
                    ]
                ],

                // 'to' Mapping (Array von Objekten mit 'name' und 'address')
                "to" => [
                    [
                        "name"    => "Versandabteilung",
                        "address" => $recipient
                    ]
                ],

                "subject" => 'Transland Label - Auftrag ' . $orderId,
                "body"    => 'Anbei das Versandlabel für Auftrag ' . $orderId . '.',

                // 'attachments' Mapping (Wichtig: 'body' statt 'content' für den File-Inhalt)
                "attachments" => [
                    [
                        "name"        => $filename,
                        "body"        => $labelBase64, 
                        "size"        => (int)(strlen(base64_decode($labelBase64)) / 1024),
                        "contentType" => $mimeType
                    ]
                ]
            ];

            // In deinem Test-Account wird das ohne echtes Konto trotzdem validieren wollen.
            // Zum Testen der Struktur kannst du die nächste Zeile auskommentieren:
            $this->emailSendService->sendPreview($mailData);

            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', ['orderId' => $orderId]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'message' => $e->getMessage(),
                'details' => 'Prüfe ob account.id existiert und from.address valide ist.'
            ]);
        }
    }
}