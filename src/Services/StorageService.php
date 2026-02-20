<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Plugin\DataBase\Contracts\DataBaseContract;
use Plenty\Plugin\Log\Loggable;

/**
 * StorageService
 *
 * Persists shipment data between the two API steps:
 *   Step 1: Label printed (SSCC assigned) → stored here
 *   Step 2: Bordero submitted             → marked as done here
 *
 * Uses PlentyMarkets plugin database for persistence.
 * Table: transland_shipments
 */
class StorageService
{
    use Loggable;

    private DataBaseContract $database;

    public function __construct(DataBaseContract $database)
    {
        $this->database = $database;
    }

    /**
     * Persist shipment data after label has been printed.
     *
     * @param array $shipmentData  Full shipment snapshot from LabelService
     */
    public function storeShipment(array $shipmentData): void
    {
        /** @var \TranslandShipping\Models\TranslandShipment $record */
        $record = pluginApp(\TranslandShipping\Models\TranslandShipment::class);

        $record->orderId      = $shipmentData['order_id'];
        $record->pickupDate   = $shipmentData['pickup_date'] ?? date('Y-m-d');
        $record->listId       = null; // filled after Bordero submission
        $record->submitted    = false;
        $record->shipmentData = json_encode($shipmentData);
        $record->createdAt    = date('Y-m-d H:i:s');
        $record->updatedAt    = date('Y-m-d H:i:s');

        $this->database->save($record);

        $this->getLogger(__METHOD__)->info('TranslandShipping::storage.saved', [
            'orderId' => $shipmentData['order_id'],
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
        $records = $this->database->query(\TranslandShipping\Models\TranslandShipment::class)
            ->where('pickupDate', '=', $pickupDate)
            ->where('submitted', '=', 0)
            ->get();

        return array_map(function ($record) {
            $data = json_decode($record->shipmentData, true);
            $data['_record_id'] = $record->id;
            return $data;
        }, $records);
    }

    /**
     * Mark a list of shipments as successfully submitted in a Bordero.
     *
     * @param int[]  $orderIds  Array of PlentyMarkets order IDs
     * @param string $listId    The Bordero list ID
     */
    public function markShipmentsAsSubmitted(array $orderIds, string $listId): void
    {
        foreach ($orderIds as $orderId) {
            $records = $this->database->query(\TranslandShipping\Models\TranslandShipment::class)
                ->where('orderId', '=', $orderId)
                ->where('submitted', '=', 0)
                ->get();

            foreach ($records as $record) {
                $record->submitted = true;
                $record->listId    = $listId;
                $record->updatedAt = date('Y-m-d H:i:s');
                $this->database->save($record);
            }
        }
    }

    /**
     * Get submission history for a given date range.
     */
    public function getSubmissionHistory(string $from, string $to): array
    {
        return $this->database->query(\TranslandShipping\Models\TranslandShipment::class)
            ->where('pickupDate', '>=', $from)
            ->where('pickupDate', '<=', $to)
            ->where('submitted', '=', 1)
            ->get();
    }
}
