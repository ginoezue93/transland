<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Mail\Templates\Contracts\MailFactory;
use Plenty\Modules\Mail\Templates\Contracts\MailService;
use Plenty\Plugin\Log\Loggable;

/**
 * Class EmailService
 * Übernimmt den Versand von Versandlabels per E-Mail an eine in den
 * Einstellungen hinterlegte Adresse.
 */
class EmailService
{
    use Loggable;

    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Versendet ein Label als Anhang.
     *
     * @param string $labelBase64 Das Label im Base64-Format
     * @param int $orderId Die Plenty-Auftrags-ID
     * @param string $format Das Dateiformat (PDF oder ZPL)
     */
    public function sendLabelEmail(string $labelBase64, int $orderId, string $format = 'PDF'): void
    {
        $settings  = $this->settingsService->getSettings();
        $recipient = trim($settings['label_email'] ?? '');

        // 1. Validierung des Empfängers
        if (empty($recipient)) {
            $this->getLogger(__METHOD__)->warning('TranslandShipping::email.no_recipient_configured', [
                'orderId' => $orderId
            ]);
            return;
        }

        // 2. Base64-String bereinigen (Header entfernen, falls vorhanden)
        if (strpos($labelBase64, 'base64,') !== false) {
            $labelBase64 = substr($labelBase64, strpos($labelBase64, 'base64,') + 7);
        }

        // 3. Dateieigenschaften bestimmen
        $isZpl     = strtoupper($format) === 'ZPL';
        $extension = $isZpl ? 'zpl' : 'pdf';
        $mimeType  = $isZpl ? 'text/plain' : 'application/pdf';
        $filename  = 'label-auftrag-' . $orderId . '.' . $extension;
        $date      = date('d.m.Y H:i');

        try {
            /** @var MailFactory $mailFactory */
            $mailFactory = pluginApp(MailFactory::class);

            /** @var MailService $mailService */
            $mailService = pluginApp(MailService::class);

            // 4. E-Mail Objekt erstellen
            $mail = $mailFactory->create();
            
            $mail->setToEmail($recipient);
            $mail->setSubject('Transland Label – Auftrag ' . $orderId . ' (' . $date . ')');
            
            // HTML Inhalt setzen
            $htmlContent = '
                <p>Guten Tag,</p>
                <p>anbei erhalten Sie das Versandlabel für den Auftrag <strong>' . $orderId . '</strong>.</p>
                <p>Sollten Sie Probleme beim Öffnen der Datei haben, prüfen Sie bitte das Format (' . $format . ').</p>
                <br>
                <p>Dies ist eine automatisch generierte Nachricht Ihres Transland-Shipping Plugins.</p>
            ';
            $mail->setHtmlContent($htmlContent);

            // 5. Anhang hinzufügen
            $mail->addAttachment(
                base64_decode($labelBase64),
                $filename,
                $mimeType
            );

            // 6. Versand triggern
            $mailService->send($mail);

            $this->getLogger(__METHOD__)->info('TranslandShipping::email.sent_success', [
                'orderId'   => $orderId,
                'recipient' => $recipient,
                'filename'  => $filename
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::email.send_failed', [
                'orderId'   => $orderId,
                'recipient' => $recipient,
                'error'     => $e->getMessage(),
                'trace'     => substr($e->getTraceAsString(), 0, 500)
            ]);
        }
    }
}