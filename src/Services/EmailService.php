<?php

namespace TranslandShipping\Services;

use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Mail\Contracts\MailerContract;

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
        $settings  = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        if (empty($recipient)) {
            return;
        }

        // Base64-Prefix entfernen falls vorhanden
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename  = 'label-auftrag-' . $orderId . '.' . $extension;
        $date      = date('d.m.Y H:i');
        $subject   = 'Transland Label - Auftrag ' . $orderId . ' (' . $date . ')';
        $body      = '<p>Anbei das Versandlabel fuer Auftrag <strong>' . $orderId . '</strong>.</p><p>Erstellt am: ' . $date . '</p>';

        try {
            /** @var MailerContract $mailer */
            $mailer = pluginApp(MailerContract::class);

            $mailer->sendTo(
                $recipient,
                $subject,
                $body,
                [
                    [
                        'data'     => base64_decode($labelBase64),
                        'name'     => $filename,
                        'mimeType' => 'application/pdf',
                    ]
                ]
            );

            $this->getLogger(__METHOD__)->error('TranslandShipping::email.sent', [
                'orderId'   => $orderId,
                'recipient' => $recipient,
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'orderId'   => $orderId,
                'recipient' => $recipient,
                'error'     => $e->getMessage(),
                'class'     => get_class($e),
            ]);
        }
    }
}