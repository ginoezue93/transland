<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use TranslandShipping\Services\TranslandApiService;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\StorageService;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\PayloadBuilderService;
use TranslandShipping\Procedures\ShippingProcedure;
use TranslandShipping\Procedures\BorderoProcedure;
use Plenty\Modules\Cron\Services\CronContainer;
use TranslandShipping\Cron\DailyShippingListCron;

class TranslandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(TranslandRouteServiceProvider::class);
        $this->getApplication()->bind(ShippingProcedure::class);
        $this->getApplication()->bind(BorderoProcedure::class);
        $this->getApplication()->singleton(SettingsService::class);
        $this->getApplication()->singleton(TranslandApiService::class);
        $this->getApplication()->singleton(PayloadBuilderService::class);
        $this->getApplication()->singleton(StorageService::class);
        $this->getApplication()->singleton(LabelService::class);
        $this->getApplication()->singleton(ShippingListService::class);
    }

    public function boot(EventProceduresService $eventProceduresService, CronContainer $cronContainer): void
    {
        $eventProceduresService->registerProcedure(
            'TranslandShipping',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Versandanmeldung an Transland senden',
                'en' => 'Register shipment with Transland',
            ],
            '\TranslandShipping\Procedures\ShippingProcedure@run'
        );

        $eventProceduresService->registerProcedure(
            'TranslandShipping',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Tagesabschluss an Transland senden (Bordero)',
                'en' => 'Submit daily Bordero to Transland',
            ],
            '\TranslandShipping\Procedures\BorderoProcedure@run'
        );

        // Register daily cron job
        $cronContainer->add(CronContainer::DAILY, DailyShippingListCron::class, 0);
    }
}