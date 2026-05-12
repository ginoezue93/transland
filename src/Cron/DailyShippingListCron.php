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
        // Debug-Log GANZ AM ANFANG — vor jedem Check.
        // Damit sehen wir ob Plenty den Cron überhaupt aufruft.
        $utcMonth = (int)date('n');
        $offsetSeconds = ($utcMonth >= 4 && $utcMonth <= 10) ? 7200 : 3600;
        $berlinHour   = (int)date('G', time() + $offsetSeconds);
        $berlinMinute = (int)date('i', time() + $offsetSeconds);
        $serverTime   = date('Y-m-d H:i:s');
        $dow          = (int)date('N');

        $this->getLogger(__METHOD__)->error('TranslandShipping::cron.ping', [
            'serverTime'    => $serverTime,
            'serverHour'    => (int)date('G'),
            'berlinHour'    => $berlinHour,
            'berlinMinute'  => $berlinMinute,
            'dayOfWeek'     => $dow,
            'offsetSeconds' => $offsetSeconds,
        ]);

        // Wochenende überspringen (Sa=6, So=7)
        if ($dow >= 6) {
            return;
        }

        // Zeitfenster-Check: nur um 12:00 Berliner Zeit ausführen (±14 Min).
        if ($berlinHour !== 12 || $berlinMinute > 14) {
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