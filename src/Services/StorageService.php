<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Plugin\DataBase\Contracts\DataBaseContract;
use Plenty\Plugin\Log\Loggable;

/**
 * StorageService
 *
 * Persists shipment data between the two API steps:
 *   Step 1: Label printed (SSCC assigned by Zufall) → stored here
 *   Step 2: Bordero submitted to Zufall             → marked as done here
 *
 * Uses PlentyMarkets plugin database for persistence.
 * Model: TranslandShipping\Models\TranslandShipment
 */
class StorageService
{
    use Loggable;

    /**
     * FIX: DataBaseContract must NOT be injected via constructor in PlentyMarkets plugins.
     * It must be resolved at runtime via pluginApp(). Constructor DI causes
     * "Target class does not exist" errors in the plugin container.
     */
    private function db(): DataBaseContract
    {
        return pluginApp(DataBaseContract::class);
    }

    /**
     * Persist shipment data after label has been printed.
     * Must be called after every successful /label API call.
     *
     * @param array $shipmentData  Full shipment snapshot from LabelService
     *                             Must contain 'order_id' and optionally 'pickup_date'
     */
    public function storeShipment(array $shipmentData): void
    {
        /** @var \TranslandShipping\Models\TranslandShipment $record */
        $record = pluginApp(\TranslandShipping\Models\TranslandShipment::class);

        $record->orderId      = (int)($shipmentData['order_id'] ?? 0);
        $record->pickupDate   = $shipmentData['pickup_date'] ?? date('Y-m-d');
        $record->listId       = null;   // filled after Bordero submission
        $record->submitted    = false;
        $record->shipmentData = json_encode($shipmentData);
        $record->createdAt    = date('Y-m-d H:i:s');
        $record->updatedAt    = date('Y-m-d H:i:s');

        $this->db()->save($record);

        $this->getLogger(__METHOD__)->info('TranslandShipping::storage.saved', [
            'orderId'    => $record->orderId,
            'pickupDate' => $record->pickupDate,
        ]);
    }

    /**
     * Get all shipments that have been label-printed but not yet submitted in a Bordero.
     *
     * @param string $pickupDate  YYYY-MM-DD
     * @return array
     */
    public function getPendingShipments(string $pickupDate): array
    {
        $records = $this->db()->query(\TranslandShipping\Models\TranslandShipment::class)
            ->where('pickupDate', '=', $pickupDate)
            ->where('submitted', '=', 0)
            ->get();

        return array_map(function ($record) {
            $data = json_decode($record->shipmentData, true) ?? [];
            $data['_record_id'] = $record->id;
            return $data;
        }, $records);
    }

    /**
     * Mark a list of shipments as successfully submitted in a Bordero.
     *
     * @param int[]  $orderIds  Array of PlentyMarkets order IDs
     * @param string $listId    The Bordero list ID (generated locally)
     */
    public function markShipmentsAsSubmitted(array $orderIds, string $listId): void
    {
        foreach ($orderIds as $orderId) {
            $records = $this->db()->query(\TranslandShipping\Models\TranslandShipment::class)
                ->where('orderId', '=', (int)$orderId)
                ->where('submitted', '=', 0)
                ->get();

            foreach ($records as $record) {
                $record->submitted = true;
                $record->listId    = $listId;
                $record->updatedAt = date('Y-m-d H:i:s');
                $this->db()->save($record);
            }
        }

        $this->getLogger(__METHOD__)->info('TranslandShipping::storage.submitted', [
            'orderIds' => $orderIds,
            'listId'   => $listId,
        ]);
    }

    /**
     * Get submission history for a given date range.
     *
     * @param string $from  YYYY-MM-DD
     * @param string $to    YYYY-MM-DD
     * @return array
     */
    public function getSubmissionHistory(string $from, string $to): array
    {
        return $this->db()->query(\TranslandShipping\Models\TranslandShipment::class)
            ->where('pickupDate', '>=', $from)
            ->where('pickupDate', '<=', $to)
            ->where('submitted', '=', 1)
            ->get();
    }
}