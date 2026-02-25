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