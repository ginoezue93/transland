<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Contracts\MailerContractFactory;
use Plenty\Plugin\Log\Loggable;

class EmailService
{
    use Loggable;

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Sendet das Label-PDF per Email.
     * Wird direkt nach dem Label-Druck aufgerufen.
     */
    public function sendLabelEmail(string $labelBase64, int $orderId, string $format = 'PDF'): void
    {
        $settings = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        if (empty($recipient)) {
            return; // Kein Versand konfiguriert
        }

        // Base64-Prefix entfernen falls vorhanden
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension  = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename   = 'label-auftrag-' . $orderId . '.' . $extension;
        $mimeType   = $extension === 'pdf' ? 'application/pdf' : 'application/octet-stream';
        $date       = date('d.m.Y H:i');

        try {
            /** @var \Plenty\Modules\Mail\Contracts\MailerContract $mailer */
            $mailer = pluginApp(MailerContractFactory::class)->create();

            $mailer->to($recipient)
                ->subject('Transland Label – Auftrag ' . $orderId . ' (' . $date . ')')
                ->html(
                    '<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>' .
                    '<p>Erstellt am: ' . $date . '</p>'
                )
                ->attachData(base64_decode($labelBase64), $filename, $mimeType)
                ->send();

            $this->getLogger(__METHOD__)->error('TranslandShipping::email.sent', [
                'orderId'   => $orderId,
                'recipient' => $recipient,
                'filename'  => $filename,
            ]);

        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'orderId'   => $orderId,
                'recipient' => $recipient,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}