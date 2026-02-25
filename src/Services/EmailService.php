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

    /**
     * Versendet ein PDF (Label oder Ladeliste) per E-Mail.
     * * @param string $pdfBase64  Das PDF als Base64 String
     * @param int $orderId       Die Auftrags-ID (0 für Ladelisten/Bordero)
     * @param string $format     Das Format (PDF/ZPL)
     */
    public function sendLabelEmail(string $pdfBase64, int $orderId, string $format = 'PDF'): void
    {
        $settings = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        if (empty($recipient) || empty($pdfBase64)) {
            return;
        }

        // Base64 bereinigen (Header entfernen, falls vorhanden)
        if (strpos($pdfBase64, 'base64,') !== false) {
            $pdfBase64 = substr($pdfBase64, strpos($pdfBase64, 'base64,') + 7);
        }
        $pdfBase64 = str_replace(["\r", "\n", " "], '', $pdfBase64);

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $mimeType = ($extension === 'pdf') ? 'application/pdf' : 'text/plain';

        // Dynamischer Dateiname und Betreff
        if ($orderId > 0) {
            $filename = 'Labels_Auftrag_' . $orderId . '.' . $extension;
            $subject = 'Transland Versandlabels - Auftrag ' . $orderId;
            $body = 'Anbei erhalten Sie die Versandlabels für Auftrag ' . $orderId . '.';
        } else {
            $filename = 'Ladeliste_Transland_' . date('d-m-Y') . '.' . $extension;
            $subject = 'Transland Ladeliste / Bordero - ' . date('d.m.Y');
            $body = 'Anbei erhalten Sie die aktuelle Ladeliste (Bordero) vom ' . date('d.m.Y') . '.';
        }

        try {
            // Berechnung der Größe in KB für den Validator
            $sizeInKb = (int) ceil(strlen(base64_decode($pdfBase64)) / 1024);

            $mailData = [
                "account" => [
                    "type" => "messenger_inbox",
                    "id" => 1,
                    "name" => "Allgemeiner Kanal",
                    "from" => [
                        "name" => "Allgemeiner Kanal",
                        "address" => trim((string) ($settings['ginoezue@gmail.com'] ?? ''))
                    ]
                ],
                "to" => [
                    [
                        "name" => "Versandabteilung",
                        "address" => $recipient
                    ]
                ],
                "subject" => $subject,
                "body" => $body,
                "attachments" => [
                    [
                        "name" => (string) $filename,
                        "body" => (string) $pdfBase64,
                        "size" => $sizeInKb > 0 ? $sizeInKb : 1,
                        "contentType" => (string) $mimeType
                    ]
                ]
            ];

            $this->emailSendService->sendPreview($mailData);

            $this->getLogger(__CLASS__)->info('TranslandShipping::mail.success', [
                'orderId' => $orderId,
                'type' => $orderId > 0 ? 'Label' : 'Bordero'
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__CLASS__)->error('TranslandShipping::mail.error', [
                'message' => $e->getMessage(),
                'orderId' => $orderId
            ]);
        }
    }
}