<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Contracts\MailerContract;
use Plenty\Plugin\Log\Loggable;

class EmailService
{
    use Loggable;

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    public function sendLabelEmail(string $labelBase64, int $orderId, string $format = 'PDF'): void
    {
        $settings = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        if (empty($recipient)) {
            $this->getLogger(__METHOD__)->warning('TranslandShipping::email.no_recipient');
            return;
        }

        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename = 'label-auftrag-' . $orderId . '.' . $extension;

        try {
            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            // In PlentyONE wird die Mail über ein Array-basiertes Design beim MailerContract versendet
            $mailer->send([
                'to' => $recipient,
                'subject' => 'Transland Label – Auftrag ' . $orderId,
                'contentHtml' => '<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>',
                'attachments' => [
                    [
                        'base64Data' => $labelBase64, // Manche Versionen erwarten Rohdaten, manche Base64
                        'name' => $filename,
                        'mimeType' => ($extension === 'pdf') ? 'application/pdf' : 'text/plain'
                    ]
                ]
            ]);

            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', ['orderId' => $orderId]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'message' => $e->getMessage()
            ]);
        }
    }
}