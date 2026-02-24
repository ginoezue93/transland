<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Templates\Contracts\MailService; // Stable7 Pfad
use Plenty\Modules\Mail\Templates\Contracts\MailFactory; // Zum Erstellen der Mail
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

        // Base64 bereinigen
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename = 'label-auftrag-' . $orderId . '.' . $extension;

        try {
            // In Stable7 wird die Mail über eine Factory erzeugt
            /** @var MailFactory $mailFactory */
            $mailFactory = pluginApp(MailFactory::class);

            // In Stable7 wird der Versand über den MailService abgewickelt
            /** @var MailService $mailService */
            $mailService = pluginApp(MailService::class);

            $mail = $mailFactory->create();
            $mail->setToEmail($recipient);
            $mail->setSubject('Transland Label - Auftrag ' . $orderId);
            $mail->setHtmlContent('<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>');

            // Anhang hinzufügen
            $mail->addAttachment(
                base64_decode($labelBase64),
                $filename,
                ($extension === 'pdf') ? 'application/pdf' : 'text/plain'
            );

            // Senden
            $mailService->send($mail);

            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', ['orderId' => $orderId]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 200)
            ]);
        }
    }
}