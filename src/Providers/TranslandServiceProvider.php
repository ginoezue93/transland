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

/**
 * TranslandServiceProvider
 *
 * Main plugin service provider. Wires up:
 *  - the REST route provider
 *  - service container bindings
 *  - the native shipping service provider registration
 *  - the Bordero event procedure
 *  - the daily Bordero cron
 *
 * IMPORTANT: In conjunction with the "shippingServiceProvider" block in
 * plugin.json, the registerShippingProvider() call below makes
 * TranslandShipping appear as a native carrier option under
 *   Setup -> Orders -> Shipping -> Options -> Shipping service providers
 * and routes the "Register shipment" process action to
 * ShippingController::registerShipments().
 */
class TranslandServiceProvider extends ServiceProvider
{
    /**
     * Bind dependencies. No return type — some Stable 7 revisions are strict
     * about matching the parent signature, which has no return type declared.
     */
    public function register()
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
     * Boot: only ShippingServiceProviderService as a typed parameter — the
     * remaining services are resolved via pluginApp() to avoid DI ordering
     * issues during early boot.
     */
    public function boot(ShippingServiceProviderService $shippingServiceProviderService)
    {
        // ── 1. Native Shipping Provider Registrierung ────────────────────────
        //
        // Der Key 'TranslandShipping' MUSS exakt dem Key im plugin.json-Block
        // "shippingServiceProvider" entsprechen. plentymarkets verknüpft darüber
        // die Backend-Carrier-Auswahl mit den Controller-Actions.
        //
        // Das dritte Argument ist ein Array aus "Controller@action"-Strings.
        // Die Reihenfolge ist festgelegt:
        //   [0] => registerShipments  (RegisterShipment Prozessaktion)
        //   [1] => deleteShipments    (Stornierung)
        //   [2] => getLabels          (Label-Abruf für Printing-Sub-Aktion)
        $shippingServiceProviderService->registerShippingProvider(
            'TranslandShipping',
            [
                'de' => 'Transland Zufall Spedition',
                'en' => 'Transland Zufall Freight',
            ],
            [
                'TranslandShipping\\Controllers\\ShippingController@registerShipments',
                'TranslandShipping\\Controllers\\ShippingController@deleteShipments',
                'TranslandShipping\\Controllers\\ShippingController@getLabels',
            ]
        );

        // ── 2. Bordero Ereignisaktion ────────────────────────────────────────
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

        // ── 3. Daily Bordero Cron ────────────────────────────────────────────
        /** @var CronContainer $cronContainer */
        $cronContainer = pluginApp(CronContainer::class);
        $cronContainer->add(CronContainer::DAILY, DailyShippingListCron::class);
    }
}
