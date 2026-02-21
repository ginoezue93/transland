<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use TranslandShipping\Services\TranslandApiService;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\StorageService;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\PayloadBuilderService;
use TranslandShipping\Procedures\ShippingProcedure;

class TranslandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(TranslandRouteServiceProvider::class);

        $this->getApplication()->bind(ShippingProcedure::class);

        $this->getApplication()->singleton(SettingsService::class);
        $this->getApplication()->singleton(TranslandApiService::class);
        $this->getApplication()->singleton(PayloadBuilderService::class);
        $this->getApplication()->singleton(StorageService::class);
        $this->getApplication()->singleton(LabelService::class);
        $this->getApplication()->singleton(ShippingListService::class);
    }

    public function boot(
        Dispatcher $eventDispatcher,
        EventProceduresService $eventProceduresService
    ): void {
        // Ereignisaktion registrieren – erscheint dann unter
        // Einrichtung → Aufträge → Ereignisse → Aktionen → Plugins → Transland
        $eventProceduresService->registerProcedure(
            'TranslandShipping',
            ProcedureEntry::PROCEDURE_GROUP_ORDER,
            [
                'de' => 'Versandanmeldung an Transland senden',
                'en' => 'Register shipment with Transland',
            ],
            ShippingProcedure::class . '@run'
        );

        // Cron registrieren
        $this->getApplication()->register(TranslandScheduleProvider::class);
    }
}