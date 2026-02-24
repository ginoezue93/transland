<?php

namespace TranslandShipping\Services;

// Dies sind die korrekten Namespaces für Stable 7 / PlentyONE
use Plenty\Modules\Mail\Services\Contracts\MailService;
use Plenty\Modules\Mail\Models\Mail;
use Plenty\Plugin\Log\Loggable;

class EmailService
{
    use Loggable;

    private $settingsService;
    private $mailService;

    // Injecte den MailService direkt
    public function __construct(SettingsService $settingsService, MailService $mailService)
    {
        $this->settingsService = $settingsService;
        $this->mailService = $mailService;
    }

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

        try {
            /** @var Mail $mail */
            $mail = pluginApp(Mail::class);

            // WICHTIG: In Stable 7 werden recipients und attachments als Arrays von Arrays gesetzt
            $mail->recipients = [[
                'email' => $recipient,
                'name'  => 'Transland Logistik'
            ]];
            
            $mail->subject = 'Transland Label - Auftrag ' . $orderId;
            $mail->contentHtml = '<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>';
            
            // Anhänge hinzufügen
            $mail->attachments = [[
                'content'  => base64_decode($labelBase64),
                'name'     => $filename,
                'mimeType' => ($extension === 'pdf') ? 'application/pdf' : 'text/plain'
            ]];

            // Der eigentliche Versandbefehl
            $this->mailService->send($mail);

            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', ['orderId' => $orderId]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ]);
        }
    }
}