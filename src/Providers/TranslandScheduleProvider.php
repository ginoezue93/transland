<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\ScheduleServiceProvider;

class TranslandScheduleProvider extends ScheduleServiceProvider
{
    /**
     * Register cron jobs.
     * Daily shipping list submission runs at configurable time (default: 17:00)
     */
    public function schedule(\Plenty\Plugin\Schedule $schedule): void
    {
        $schedule->cron(\TranslandShipping\Cron\DailyShippingListCron::class)
            ->everyCronJobRun();
    }
}
