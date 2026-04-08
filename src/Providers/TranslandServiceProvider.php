<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Modules\Order\Shipping\ServiceProvider\Services\ShippingServiceProviderService;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\Cron\Services\CronContainer;
use TranslandShipping\Services\TranslandApiService;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\StorageService;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\PayloadBuilderService;
use TranslandShipping\Procedures\BorderoProcedure;
use TranslandShipping\Cron\DailyShippingListCron;

class TranslandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(TranslandRouteServiceProvider::class);
        $this->getApplication()->bind(BorderoProcedure::class);
        $this->getApplication()->singleton(SettingsService::class);
        $this->getApplication()->singleton(TranslandApiService::class);
        $this->getApplication()->singleton(PayloadBuilderService::class);
        $this->getApplication()->singleton(StorageService::class);
        $this->getApplication()->singleton(LabelService::class);
        $this->getApplication()->singleton(ShippingListService::class);
    }

    public function boot(
        ShippingServiceProviderService $shippingServiceProviderService,
        EventProceduresService         $eventProceduresService,
        CronContainer                  $cronContainer
    ): void {
        // ── Shipping Provider registrieren ───────────────────────────────────
        // Macht TranslandShipping zu einem nativen Versanddienstleister.
        // Erscheint als Option in: Einrichtung -> Versand -> Versanddienstleister
        // Und in Prozess-Aktion "RegisterShipment" als "Transland Zufall Spedition"
        $shippingServiceProviderService->registerShippingProvider(
            'TranslandShipping',
            [
                'de' => 'Transland Zufall Spedition',
                'en' => 'Transland Zufall Freight',
            ],
            [
                'TranslandShipping\\Controllers\\ShippingController@registerShipments',
                'TranslandShipping\\Controllers\\ShippingController@deleteShipments',
            ]
        );

        // ── Bordero Ereignisaktion registrieren ───────────────────────────────
        $eventProceduresService->registerProcedure(
            'TranslandShipping',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Tagesabschluss an Transland senden (Bordero)',
                'en' => 'Submit daily Bordero to Transland',
            ],
            '\\TranslandShipping\\Procedures\\BorderoProcedure@run'
        );

        // ── Taeglicher Cron-Job ───────────────────────────────────────────────
        // Laueft taeglich, prueft intern ob 12:00 Berliner Zeit ±30 Min
        $cronContainer->add(CronContainer::DAILY, DailyShippingListCron::class, 0);
    }
}
