<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\ScheduleServiceProvider;
use TranslandShipping\Services\TranslandApiService;
use TranslandShipping\Services\LabelService;
use TranslandShipping\Services\ShippingListService;

class TranslandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->singleton(TranslandApiService::class);
        $this->getApplication()->singleton(LabelService::class);
        $this->getApplication()->singleton(ShippingListService::class);
    }

    public function boot(Dispatcher $eventDispatcher): void
    {
        // Register routes
        $this->getApplication()->register(TranslandRouteServiceProvider::class);
        
        // Register cron job for daily shipping list submission
        $this->getApplication()->register(TranslandScheduleProvider::class);
    }
}
