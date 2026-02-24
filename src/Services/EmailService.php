<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Contracts\MailRepositoryContract;
use Plenty\Modules\Mail\Models\Mail;
use Plenty\Plugin\Log\Loggable;

class EmailService
{
    use Loggable;

    private $settingsService;
    private $mailRepository;

    // Nutze Dependency Injection für das Repository
    public function __construct(SettingsService $settingsService, MailRepositoryContract $mailRepository)
    {
        $this->settingsService = $settingsService;
        $this->mailRepository = $mailRepository;
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

            // Empfänger als Array von Objekten setzen
            $mail->recipients = [[
                'email' => $recipient,
                'name'  => 'Transland Logistik'
            ]];
            
            $mail->subject = 'Transland Label - Auftrag ' . $orderId;
            $mail->contentHtml = '<p>Anbei das Versandlabel für Auftrag <strong>' . $orderId . '</strong>.</p>';
            
            // Absender-Infos (Optional, falls nicht über Standard-Konto)
            // $mail->senderEmail = "info@deinshop.de";

            // Anhang hinzufügen: In Stable7 ist das Feld 'attachments' ein Array
            $mail->attachments = [[
                'content'  => base64_decode($labelBase64),
                'name'     => $filename,
                'mimeType' => ($extension === 'pdf') ? 'application/pdf' : 'text/plain'
            ]];

            // Senden über das Repository
            $this->mailRepository->sendMail($mail);

            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', ['orderId' => $orderId]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.error', [
                'message' => $e->getMessage(),
                'trace'   => substr($e->getTraceAsString(), 0, 200)
            ]);
        }
    }
}