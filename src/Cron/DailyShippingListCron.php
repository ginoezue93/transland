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

    /**
     * Main cron execution method.
     * PlentyMarkets calls this every 15 minutes.
     */
    public function handle(): void
    {
        $settings = $this->settingsService->getSettings();

        // Only run if auto-submit is enabled
        if (!($settings['auto_submit_enabled'] ?? true)) {
            return;
        }

        // Check if we're within the configured submission time window (±7 min)
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
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Check if current time is within ±$windowMinutes of the target time.
     *
     * @param string $targetTime  Format: "HH:MM"
     * @param int    $windowMinutes
     */
    private function isWithinTimeWindow(string $targetTime, int $windowMinutes = 7): bool
    {
        [$targetH, $targetM] = explode(':', $targetTime);
        $targetMinutes  = (int)$targetH * 60 + (int)$targetM;
        $currentMinutes = (int)date('H') * 60 + (int)date('i');

        return abs($currentMinutes - $targetMinutes) <= $windowMinutes;
    }
}
