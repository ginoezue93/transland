<?php

namespace TranslandShipping\Services;

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
        $this->getLogger(__METHOD__)->error('TranslandShipping::email.called', [
            'orderId' => $orderId,
            'format'  => $format,
        ]);

        $settings  = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        $this->getLogger(__METHOD__)->error('TranslandShipping::email.recipient', [
            'recipient' => $recipient,
            'empty'     => empty($recipient) ? 'JA' : 'NEIN',
        ]);

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

        try {
            $transport = pluginApp(\Plenty\Modules\Mail\Contracts\MailMessageContract::class);

            $this->getLogger(__METHOD__)->error('TranslandShipping::email.transport_created', [
                'class' => get_class($transport),
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.transport_error', [
                'error' => $e->getMessage(),
                'class' => 'Plenty\\Modules\\Mail\\Contracts\\MailMessageContract',
            ]);
        }

        try {
            /** @var \Plenty\Modules\Mail\Contracts\MailerContract $mailer */
            $mailer = pluginApp(\Plenty\Modules\Mail\Contracts\MailerContract::class);

            $this->getLogger(__METHOD__)->error('TranslandShipping::email.mailer_created', [
                'class' => get_class($mailer),
            ]);

            $mailer->sendTo(
                $recipient,
                'Transland Label – Auftrag ' . $orderId . ' (' . $date . ')',
                '<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>',
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
                'trace'     => substr($e->getTraceAsString(), 0, 500),
            ]);
        }
    }
}