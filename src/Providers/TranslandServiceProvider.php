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

    /**
     * Boot method – only ShippingServiceProviderService as parameter.
     * Following the official PlentyONE shipping plugin tutorial exactly.
     * Other services (EventProcedures, Cron) are resolved via pluginApp().
     */
    public function boot(ShippingServiceProviderService $shippingServiceProviderService): void
    {
        // ── Shipping Provider registrieren ───────────────────────────────────
        // Erscheint als Option in: Setup -> Orders -> Shipping -> Shipping service providers
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

        // ── Bordero Ereignisaktion ────────────────────────────────────────────
        /** @var EventProceduresService $eventProceduresService */
        $eventProceduresService = pluginApp(EventProceduresService::class);
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
        /** @var CronContainer $cronContainer */
        $cronContainer = pluginApp(CronContainer::class);
        $cronContainer->add(CronContainer::DAILY, DailyShippingListCron::class, 0);
    }
}
