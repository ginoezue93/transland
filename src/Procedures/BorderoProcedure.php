<?php

namespace TranslandShipping\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\StorageService;
use TranslandShipping\Services\ShippingListService;

class BorderoProcedure
{
    use Loggable;

    public function run(EventProceduresTriggered $event): void
    {
        try {
            /** @var StorageService $storage */
            $storage = pluginApp(StorageService::class);

            // Get ALL pending shipments regardless of date
            $pending = $storage->getPendingShipments();

            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.pending', [
                'count' => count($pending),
            ]);

            if (empty($pending)) {
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.noPending', [
                    'message' => 'Keine ausstehenden Sendungen',
                ]);
                return;
            }

            // Group by pickup date and submit each date separately
            $byDate = [];
            foreach ($pending as $shipment) {
                $date = $shipment['pickup_date'] ?? date('Y-m-d');
                $byDate[$date][] = $shipment;
            }

            /** @var ShippingListService $service */
            $service = pluginApp(ShippingListService::class);

            foreach ($byDate as $date => $shipments) {
                $result = $service->submitDailyShipments($date, true);

                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.result', [
                    'date'           => $date,
                    'result'         => $result['result'],
                    'list_id'        => $result['list_id'] ?? '',
                    'shipment_count' => $result['shipment_count'] ?? 0,
                ]);
            }

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }
}