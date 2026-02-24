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
        
        // Daten für den Mock vorbereiten
        $recipient = trim($settings['label_email'] ?? 'test-empfaenger@example.com');
        $senderEmail = trim($settings['sender_email'] ?? 'test-absender@example.com');

        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename = 'label-auftrag-' . $orderId . '.' . $extension;

        /**
         * MOCK-LOGIK START
         * Wir bauen das Array exakt nach Doku, senden es aber nicht ab,
         * um den Validation Error im Test-Account zu umgehen.
         */
        $mailData = [
            "accountId"   => (isset($settings['mail_account_id'])) ? (int)$settings['mail_account_id'] : 1,
            "fromAddress" => $senderEmail,
            "fromName"    => "Transland Mock Service",
            "subject"     => 'MOCK: Transland Label - Auftrag ' . $orderId,
            "content"     => 'Simulation des Mail-Versands für Auftrag ' . $orderId,
            "receivers"   => [
                "to" => [["email" => $recipient, "name" => "Test Kunde"]]
            ],
            "attachments" => [
                [
                    "content" => substr($labelBase64, 0, 50) . '... [truncated]', // Nur Anfang loggen
                    "name"    => $filename,
                    "type"    => ($extension === 'pdf') ? 'application/pdf' : 'text/plain'
                ]
            ]
        ];

        // Wir simulieren den Erfolg im Log
        $this->getLogger(__METHOD__)->info('TranslandShipping::email.mock_success', [
            'orderId' => $orderId,
            'simulatedPayload' => $mailData
        ]);

        /* * Sobald du in einem echten Account bist, aktiviere einfach wieder:
         * $this->emailSendService->sendPreview($mailData);
         */
    }
}