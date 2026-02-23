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

        $record->orderId    = (int)($shipmentData['order_id'] ?? 0);
        $record->pickupDate = $shipmentData['pickup_date'] ?? date('Y-m-d');
        $record->listId     = '';
        $record->submitted  = 0;
        $record->createdAt  = date('Y-m-d H:i:s');
        $record->updatedAt  = date('Y-m-d H:i:s');

        // UTF-8 sicherstellen und json_encode-Fehler abfangen
        $encoded = json_encode($shipmentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            // Fallback: Umlaute und Sonderzeichen bereinigen
            $clean = $this->sanitizeForJson($shipmentData);
            $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $record->shipmentData = $encoded ?: '{}';

        $this->db()->save($record);

        // Verifizieren was wirklich gespeichert wurde
        $decoded = json_decode($record->shipmentData, true) ?? [];

        $this->getLogger(__METHOD__)->error('TranslandShipping::storage.saved', [
            'orderId'             => $record->orderId,
            'pickupDate'          => $record->pickupDate,
            'json_encode_ok'      => ($encoded !== false && $encoded !== '{}') ? 'JA' : 'FEHLER',
            'has_shipper_address' => !empty($decoded['shipper_address']) ? 'JA' : 'NEIN',
            'shipper_name1'       => $decoded['shipper_address']['name1'] ?? 'LEER',
            'has_consignee'       => !empty($decoded['consignee_address']) ? 'JA' : 'NEIN',
            'consignee_name1'     => $decoded['consignee_address']['name1'] ?? 'LEER',
            'reference'           => $decoded['reference'] ?? 'LEER',
            'package_count'       => count($decoded['packages'] ?? []),
            'shipmentData_length' => strlen($record->shipmentData),
        ]);
    }

    /**
     * Bereinigt ein Array rekursiv für JSON-Encoding.
     * Konvertiert alle Strings nach UTF-8.
     */
    private function sanitizeForJson($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeForJson'], $data);
        }
        if (is_string($data)) {
            // Wenn nicht UTF-8, konvertieren
            if (!mb_check_encoding($data, 'UTF-8')) {
                return mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
            }
        }
        return $data;
    }

    public function getPendingShipments(string $newerThan = ''): array
    {
        $cutoff = !empty($newerThan) ? $newerThan : date('Y-m-d');

        $records = $this->db()->query(TranslandShipment::class)
            ->where('submitted', '=', 0)
            ->where('createdAt', '>=', $cutoff . ' 00:00:00')
            ->get();

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

    /**
     * Markiert alle ungültigen pending Records (leere shipper_address) als submitted.
     */
    public function purgeInvalidRecords(): int
    {
        $records = $this->db()->query(TranslandShipment::class)
            ->where('submitted', '=', 0)
            ->get();

        $count = 0;
        foreach ($records as $record) {
            $data = json_decode($record->shipmentData, true) ?? [];
            if (empty($data['shipper_address']) || empty($data['reference'])) {
                $record->submitted = 1;
                $record->listId    = 'PURGED-INVALID';
                $record->updatedAt = date('Y-m-d H:i:s');
                $this->db()->save($record);
                $count++;
            }
        }

        return $count;
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