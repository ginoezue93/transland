<?php

namespace TranslandShipping\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\ShippingListService;

class BorderoProcedure
{
    use Loggable;

    public function run(EventProceduresTriggered $event): void
    {
        try {
            /** @var ShippingListService $service */
            $service = pluginApp(ShippingListService::class);
            $result  = $service->submitDailyShipments(date('Y-m-d'), true);

            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.result', [
                'result'        => $result['result'],
                'list_id'       => $result['list_id'] ?? '',
                'shipment_count'=> $result['shipment_count'] ?? 0,
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }
}