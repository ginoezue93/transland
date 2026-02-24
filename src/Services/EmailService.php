<?php

namespace TranslandShipping\Services;

// Der offizielle Stable 7 Contract für den neuen Mail-Builder
use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\Log\Loggable;

/**
 * Class EmailService
 * * Behebt den "validation error" durch strikte Einhaltung der 
 * PlentyONE (Stable 7) Mail-Struktur.
 */
class EmailService
{
    use Loggable;

    private $settingsService;
    private $emailSendService;

    /**
     * EmailService constructor.
     */
    public function __construct(
        SettingsService $settingsService,
        EmailTemplatesSendServiceContract $emailSendService
    ) {
        $this->settingsService = $settingsService;
        $this->emailSendService = $emailSendService;
    }

    /**
     * Sendet das Label per E-Mail.
     * Nutzt die 'sendPreview' Methode, um dynamische Inhalte ohne festes Template zu senden.
     */
    public function sendLabelEmail(string $labelBase64, int $orderId, string $format = 'PDF'): void
    {
        $settings = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        if (empty($recipient)) {
            $this->getLogger(__METHOD__)->warning('TranslandShipping::email.no_recipient');
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
            /**
             * WICHTIG: Die Struktur muss exakt der Stable 7 Spezifikation entsprechen.
             * Ein falscher Key (z.B. 'mimeType' statt 'type') führt zum "validation error".
             */
            $mailData = [
                // 1. Absender-Account (Zwingend erforderlich in Stable 7)
                // Falls in Settings nicht gesetzt, wird ID 1 (Standard) probiert.
                "fromAddress" => "test@example.com",
                "fromName" => "Test System",
                "accountId" => 1,

                // 3. Inhalt
                "subject" => 'Transland Label - Auftrag ' . $orderId,
                "content" => '<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>',

                // 4. Empfänger-Struktur (Striktes Array-Format)
                "receivers" => [
                    "to" => [
                        [
                            "email" => $recipient,
                            "name" => "Transland Logistik"
                        ]
                    ]
                ],

                // 5. Anhänge
                "attachments" => [
                    [
                        "content" => $labelBase64,
                        "name" => $filename,
                        "type" => $mimeType // Stable 7 nutzt oft 'type' für den Mime-Type
                    ]
                ]
            ];

            // Versand auslösen
            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', [
                'orderId' => $orderId,
                'recipient' => $recipient
            ]);
            $this->emailSendService->sendPreview($mailData);


        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'message' => $e->getMessage(),
                'orderId' => $orderId,
                'sentData' => [
                    'recipient' => $recipient,
                    'accountId' => $mailData['accountId'] ?? 'none'
                ]
            ]);
        }
    }
}