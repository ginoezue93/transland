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
            'createdAt'  => $record->createdAt,
        ]);
    }

    /**
     * Gibt alle pending Shipments zurück – aber nur den jeweils neuesten Record
     * pro Auftrag (anhand createdAt), und nur Records die nach $newerThan erstellt wurden.
     *
     * @param string $newerThan  Datum im Format 'Y-m-d' – nur Records ab diesem Tag.
     *                           Default: heute. Leer = kein Datumsfilter.
     */
    public function getPendingShipments(string $newerThan = ''): array
    {
        // Standard: nur Records von heute oder neuer
        if ($newerThan === null) {
            $newerThan = '';
        }
        $cutoff = !empty($newerThan) ? $newerThan : date('Y-m-d');

        $query = $this->db()->query(TranslandShipment::class)
            ->where('submitted', '=', 0)
            ->where('createdAt', '>=', $cutoff . ' 00:00:00');

        $records = $query->get();

        if (empty($records)) {
            return [];
        }

        // Pro orderId nur den neuesten Record behalten
        $latestByOrder = [];
        foreach ($records as $record) {
            $orderId = $record->orderId;
            if (
                !isset($latestByOrder[$orderId]) ||
                $record->createdAt > $latestByOrder[$orderId]->createdAt
            ) {
                $latestByOrder[$orderId] = $record;
            }
        }

        return array_values(array_map(function ($record) {
            $data = json_decode($record->shipmentData, true) ?? [];
            $data['_record_id']  = $record->id;
            $data['pickup_date'] = $record->pickupDate;
            return $data;
        }, $latestByOrder));
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