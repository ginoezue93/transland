<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Models\MailMessage;
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
        $settings  = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        if (empty($recipient)) {
            return;
        }

        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        $extension = strtolower($format) === 'zpl' ? 'zpl' : 'pdf';
        $filename  = 'label-auftrag-' . $orderId . '.' . $extension;

        try {
            // Wir erstellen das Modell direkt, anstatt es über die App-Factory zu laden
            /** @var MailMessage $mailMessage */
            $mailMessage = pluginApp(MailMessage::class);

            $mailMessage->toEmail   = $recipient;
            $mailMessage->subject   = 'Transland Label - Auftrag ' . $orderId;
            $mailMessage->contentHtml = '<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>';
            
            // Bei Modellen werden Anhänge oft direkt als Array zugewiesen
            $mailMessage->attachments = [
                [
                    'base64Data' => $labelBase64,
                    'name'       => $filename,
                    'mimeType'   => ($extension === 'pdf') ? 'application/pdf' : 'text/plain'
                ]
            ];

            /** @var \Plenty\Modules\Mail\Contracts\MailerContract $mailer */
            // Falls der MailerContract als Klasse nicht gefunden wird, 
            // versuchen wir es über den Service-Container-Key 'mailer'
            $mailer = pluginApp('mailer'); 
            $mailer->send($mailMessage);

            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', ['orderId' => $orderId]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.failed', [
                'error' => $e->getMessage(),
                'hint'  => 'Versuche alternative Mail-Methode...'
            ]);
            
            // Backup: Falls das Modell auch scheitert, nutzen wir das globale Mail-System
            $this->sendBackupMail($recipient, $orderId, $labelBase64, $filename);
        }
    }

    private function sendBackupMail($recipient, $orderId, $data, $filename)
    {
        try {
            // Letzter Versuch über das MailService-Modul mit String-Key
            $mailService = pluginApp('Plenty\Modules\Mail\Contracts\MailService');
            $mailService->send([
                'to' => $recipient,
                'subject' => 'Transland Label - ' . $orderId,
                'content' => 'Label im Anhang.',
                'attachments' => [[
                    'base64Data' => $data,
                    'name' => $filename
                ]]
            ]);
        } catch (\Throwable $e) {
             $this->getLogger(__METHOD__)->error('TranslandShipping::email.all_methods_failed');
        }
    }
}