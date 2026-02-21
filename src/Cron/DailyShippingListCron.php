<?php

namespace TranslandShipping\Cron;

use Plenty\Modules\Cron\Services\CronHandler;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\SettingsService;

/**
 * DailyShippingListCron
 *
 * Runs daily and submits all pending shipments as a Bordero to Transland.
 * Execution time is configured in plugin settings (default: 17:00).
 *
 * The cron runs every 15 minutes in PlentyMarkets; the time window check
 * ensures the actual submission only happens once at the configured time.
 */
class DailyShippingListCron extends CronHandler
{
    use Loggable;

    private ShippingListService $shippingListService;
    private SettingsService     $settingsService;

    public function __construct(
        ShippingListService $shippingListService,
        SettingsService     $settingsService
    ) {
        $this->shippingListService = $shippingListService;
        $this->settingsService     = $settingsService;
    }

    public function handle(): void
    {
        $settings = $this->settingsService->getSettings();

        if (!($settings['auto_submit_enabled'] ?? true)) {
            return;
        }

        $configuredTime = $settings['auto_submit_time'] ?? '17:00';
        if (!$this->isWithinTimeWindow($configuredTime, 7)) {
            return;
        }

        $this->getLogger(__METHOD__)->info('TranslandShipping::cron.start', [
            'time' => date('H:i:s'),
        ]);

        try {
            $returnList = (bool)($settings['return_ladeliste_pdf'] ?? true);
            $result     = $this->shippingListService->submitDailyShipments(date('Y-m-d'), $returnList);

            $this->getLogger(__METHOD__)->info('TranslandShipping::cron.success', [
                'result'         => $result['result'],
                'shipment_count' => $result['shipment_count'] ?? 0,
                'list_id'        => $result['list_id'] ?? null,
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::cron.error', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if current time is within ±$windowMinutes of the target time.
     * Uses max() instead of abs() which is not allowed in PlentyONE plugins.
     */
    private function isWithinTimeWindow(string $targetTime, int $windowMinutes = 7): bool
    {
        $parts         = explode(':', $targetTime);
        $targetH       = (int)($parts[0] ?? 0);
        $targetM       = (int)($parts[1] ?? 0);
        $targetMinutes = $targetH * 60 + $targetM;
        $currentMinutes = (int)date('H') * 60 + (int)date('i');

        // Use max/min instead of abs() which is not allowed in PlentyONE
        $diff = $currentMinutes - $targetMinutes;
        $diff = max($diff, -$diff);

        return $diff <= $windowMinutes;
    }
}
