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

            $pending = $storage->getPendingShipments();

            if (empty($pending)) {
                $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.noPending', []);
                return;
            }

            // Log only the first shipment keys and key values
            $first = $pending[0];
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.firstShipment', [
                'keys'            => implode(', ', array_keys($first)),
                'reference'       => $first['reference'] ?? 'MISSING',
                'weight_gr'       => $first['weight_gr'] ?? 'MISSING',
                'value'           => $first['value'] ?? 'MISSING',
                'pickup_date'     => $first['pickup_date'] ?? 'MISSING',
                'has_shipper'     => isset($first['shipper_address']) ? 'YES' : 'NO',
                'has_consignee'   => isset($first['consignee_address']) ? 'YES' : 'NO',
                'has_packages'    => isset($first['packages']) ? count($first['packages']) : 'NO',
            ]);

        } catch (\Throwable $e) {
            $this->getLogger(__METHOD__)->error('TranslandShipping::bordero.error', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}