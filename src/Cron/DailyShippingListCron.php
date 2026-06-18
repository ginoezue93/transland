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
        $dow  = (int)date('N');
        $hour = (int)date('G');
        $min  = (int)date('i');

        $this->getLogger(__METHOD__)->error('TranslandShipping::cron.ping', [
            'serverTime' => date('Y-m-d H:i:s'),
            'hour'       => $hour,
            'minute'     => $min,
            'dayOfWeek'  => $dow,
        ]);

        // Wochenende überspringen (Sa=6, So=7)
        if ($dow >= 6) {
            return;
        }

        // Nur um 06:00-06:14 oder 12:00-12:14 ausführen.
        $isTime = ($hour === 6 || $hour === 12) && $min <= 14;
        if (!$isTime) {
            return;
        }

        /** @var SettingsService $settingsService */
        $settingsService = pluginApp(SettingsService::class);
        $settings        = $settingsService->getSettings();

        $this->getLogger(__METHOD__)->error('TranslandShipping::cron.start', [
            'time' => date('H:i:s'),
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