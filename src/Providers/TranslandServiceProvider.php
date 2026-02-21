<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use TranslandShipping\Services\TranslandApiService;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\StorageService;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\PayloadBuilderService;

class TranslandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(TranslandRouteServiceProvider::class);

        $this->getApplication()->singleton(SettingsService::class);
        $this->getApplication()->singleton(TranslandApiService::class);
        $this->getApplication()->singleton(PayloadBuilderService::class);
        $this->getApplication()->singleton(StorageService::class);
        $this->getApplication()->singleton(LabelService::class);
        $this->getApplication()->singleton(ShippingListService::class);
    }

    public function boot(Dispatcher $eventDispatcher): void
    {
        $this->getApplication()->register(TranslandScheduleProvider::class);
    }
}