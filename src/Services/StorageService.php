<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Models\TranslandShipment;

class StorageService
{
    use Loggable;

    private function db(): DataBase
    {
        return pluginApp(DataBase::class);
    }

    public function storeShipment(array $shipmentData): void
    {
        /** @var TranslandShipment $record */
        $record = pluginApp(TranslandShipment::class);

        $record->orderId      = (int)($shipmentData['order_id'] ?? 0);
        $record->pickupDate   = $shipmentData['pickup_date'] ?? date('Y-m-d');
        $record->listId       = '';
        $record->submitted    = 0;
        $record->shipmentData = json_encode($shipmentData);
        $record->createdAt    = date('Y-m-d H:i:s');
        $record->updatedAt    = date('Y-m-d H:i:s');

        $this->db()->save($record);

        $this->getLogger(__METHOD__)->error('TranslandShipping::storage.saved', [
            'orderId'    => $record->orderId,
            'pickupDate' => $record->pickupDate,
        ]);
    }

    /**
     * Get pending shipments for a specific date.
     * If no date given, returns ALL pending shipments regardless of date.
     */
    public function getPendingShipments(string $pickupDate = ''): array
    {
        $query = $this->db()->query(TranslandShipment::class)
            ->where('submitted', '=', 0);

        if (!empty($pickupDate)) {
            $query = $query->where('pickupDate', '=', $pickupDate);
        }

        $records = $query->get();

        return array_map(function ($record) {
            $data = json_decode($record->shipmentData, true) ?? [];
            $data['_record_id']  = $record->id;
            $data['pickup_date'] = $record->pickupDate;
            return $data;
        }, $records);
    }

    public function markShipmentsAsSubmitted(array $orderIds, string $listId): void
    {
        foreach ($orderIds as $orderId) {
            $records = $this->db()->query(TranslandShipment::class)
                ->where('orderId', '=', (int)$orderId)
                ->where('submitted', '=', 0)
                ->get();

            foreach ($records as $record) {
                $record->submitted = 1;
                $record->listId    = $listId;
                $record->updatedAt = date('Y-m-d H:i:s');
                $this->db()->save($record);
            }
        }

        $this->getLogger(__METHOD__)->error('TranslandShipping::storage.submitted', [
            'orderIds' => $orderIds,
            'listId'   => $listId,
        ]);
    }

    public function getSubmissionHistory(string $from, string $to): array
    {
        return $this->db()->query(TranslandShipment::class)
            ->where('pickupDate', '>=', $from)
            ->where('pickupDate', '<=', $to)
            ->where('submitted', '=', 1)
            ->get();
    }
}