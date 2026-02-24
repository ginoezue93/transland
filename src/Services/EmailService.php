<?php

namespace TranslandShipping\Services;

// Der Pfad aus der Dokumentation (Namespace + Interface Name)
use Plenty\Modules\Mail\Templates\Contracts\Service\EmailService\EmailTemplatesSendServiceContract;
use Plenty\Plugin\Log\Loggable;

class EmailService
{
    use Loggable;

    private $settingsService;
    private $emailSendService;

    // Das Interface wird hier injiziert
    public function __construct(
        SettingsService $settingsService, 
        EmailTemplatesSendServiceContract $emailSendService
    ) {
        $this->settingsService = $settingsService;
        $this->emailSendService = $emailSendService;
    }

    public function sendLabelEmail(string $labelBase64, int $orderId, string $format = 'PDF'): void
    {
        // ... (dein restlicher Code)
        
        // Die Methode im Interface heißt 'sendPreview' für eigene Inhalte
        $this->emailSendService->sendPreview([
            "subject" => "Versandlabel für Auftrag " . $orderId,
            "body"    => "Anbei das Label.",
            "receivers" => [
                "to" => [["email" => $recipient, "name" => "Kunde"]]
            ]
            // ... Anhänge
        ]);
    }
}