<?php

namespace TranslandShipping\Cron;

use Plenty\Modules\Cron\Contracts\CronHandler;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\ShippingListService;
use TranslandShipping\Services\SettingsService;

/**
 * DailyShippingListCron
 *
 * Runs daily and submits all pending shipments as a Bordero to Transland.
 * Registered via CronContainer::DAILY in TranslandServiceProvider.
 */
class DailyShippingListCron extends CronHandler
{
    use Loggable;

    public function handle(): void
    {
        // Wochenende überspringen (Sa=6, So=7)
        $dow = (int)date('N');
        if ($dow >= 6) {
            return;
        }

        // Zeitfenster-Check: nur um 12:00 Berliner Zeit ausführen (±30 Min)
        // Server läuft auf UTC: Winter UTC+1, Sommer UTC+2
        $utcMonth = (int)date('n');
        $offsetSeconds = ($utcMonth >= 4 && $utcMonth <= 10) ? 7200 : 3600;
        $berlinHour   = (int)date('G', time() + $offsetSeconds);
        $berlinMinute = (int)date('i', time() + $offsetSeconds);
        $berlinMinutes = $berlinHour * 60 + $berlinMinute;
        $targetMinutes = 12 * 60; // 12:00
        $diff = $berlinMinutes - $targetMinutes;
        $diff = max($diff, -$diff);
        if ($diff > 30) {
            return;
        }

        /** @var SettingsService $settingsService */
        $settingsService = pluginApp(SettingsService::class);
        $settings        = $settingsService->getSettings();

        $this->getLogger(__METHOD__)->error('TranslandShipping::cron.start', [
            'time'       => date('H:i:s'),
            'berlin_time' => $berlinHour . ':' . str_pad((string)$berlinMinute, 2, '0', 0),
        ]);

        try {
            $returnList = (bool)($settings['return_ladeliste_pdf'] ?? true);

            /** @var ShippingListService $shippingListService */
            $shippingListService = pluginApp(ShippingListService::class);
            $result = $shippingListService->submitDailyShipments('', $returnList);

            $this->getLogger(__METHOD__)->error('TranslandShipping::cron.success', [
                'result'         => $result['result'],
                'shipment_count' => $result['shipment_count'] ?? 0,
                'list_id'        => $result['list_id'] ?? '',
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::cron.error', [
                'message' => $e->getMessage(),
                'trace'   => substr($e->getTraceAsString(), 0, 500),
            ]);
        }
    }
}