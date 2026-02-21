<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\TranslandApiService;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\StorageService;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\PayloadBuilderService;
use TranslandShipping\Procedures\ShippingProcedure;

class TranslandServiceProvider extends ServiceProvider
{
    use Loggable;

    public function register(): void
    {
        // loadMigration() existiert nicht in PlentyONE - entfernt!
        // Modelle werden automatisch erkannt wenn sie Model erweitern

        $this->getApplication()->register(TranslandRouteServiceProvider::class);
        $this->getApplication()->bind(ShippingProcedure::class);
        $this->getApplication()->singleton(SettingsService::class);
        $this->getApplication()->singleton(TranslandApiService::class);
        $this->getApplication()->singleton(PayloadBuilderService::class);
        $this->getApplication()->singleton(StorageService::class);
        $this->getApplication()->singleton(LabelService::class);
        $this->getApplication()->singleton(ShippingListService::class);
    }

    public function boot(EventProceduresService $eventProceduresService): void
    {
        $this->getLogger(__CLASS__)->error('TranslandShipping::ServiceProvider.boot', [
            'message' => 'boot() wurde aufgerufen',
            'time'    => date('Y-m-d H:i:s'),
        ]);

        $result = $eventProceduresService->registerProcedure(
            'TranslandShipping',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Versandanmeldung an Transland senden',
                'en' => 'Register shipment with Transland',
            ],
            '\TranslandShipping\Procedures\ShippingProcedure@run'
        );

        $this->getLogger(__CLASS__)->error('TranslandShipping::ServiceProvider.registered', [
            'result' => $result,
        ]);

        $this->getApplication()->register(TranslandScheduleProvider::class);
    }
}