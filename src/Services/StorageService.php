<?php

namespace TranslandShipping\Services;

use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Models\Shipment;
use TranslandShipping\Services\SettingsService;
use TranslandShipping\Services\PayloadBuilderService;

class StorageService
{
    use Loggable;

    private function db(): DataBase
    {
        return pluginApp(DataBase::class);
    }

    public function storeShipment(array $data): void
    {
        /** @var Shipment $record */
        $record = pluginApp(Shipment::class);

        $consignee = $data['consignee_address'] ?? [];

        $record->orderId          = (int)($data['order_id'] ?? 0);
        $record->pickupDate       = $data['pickup_date'] ?? date('Y-m-d');
        $record->listId           = '';
        $record->submitted        = 0;

        $record->consigneeName1   = $consignee['name1']          ?? '';
        $record->consigneeName2   = $consignee['name2']          ?? '';
        $record->consigneeStreet  = $consignee['street']         ?? '';
        $record->consigneeZip     = $consignee['zip']            ?? '';
        $record->consigneeCity    = $consignee['city']           ?? '';
        $record->consigneeCountry = $consignee['country']        ?? 'DE';
        $record->consigneePhone   = $consignee['phone']          ?? '';
        $record->consigneeEmail   = $consignee['email']          ?? '';
        $record->consigneeContact = $consignee['contact_person'] ?? '';

        $record->reference        = (string)($data['reference']       ?? '');
        $record->value            = (string)($data['value']           ?? '0');
        $record->valueCurrency    = (string)($data['value_currency']  ?? 'EUR');
        $record->weightGr         = (int)($data['weight_gr']          ?? 0);

        $record->packagesJson     = json_encode($data['packages'] ?? [], JSON_UNESCAPED_UNICODE);
        $record->optionsJson      = json_encode($data['options']  ?? [], JSON_UNESCAPED_UNICODE);

        $record->createdAt        = date('Y-m-d H:i:s');
        $record->updatedAt        = date('Y-m-d H:i:s');

        $this->db()->save($record);

        $this->getLogger(__METHOD__)->error('TranslandShipping::storage.saved', [
            'orderId'          => $record->orderId,
            'table'            => $record->getTableName(),
            'consigneeName1'   => $record->consigneeName1,
            'reference'        => $record->reference,
            'weightGr'         => $record->weightGr,
            'packagesJson_len' => strlen($record->packagesJson),
        ]);
    }

    public function getPendingShipments(string $newerThan = ''): array
    {
        $cutoff = !empty($newerThan) ? $newerThan : date('Y-m-d');

        $records = $this->db()->query(Shipment::class)
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

        return array_values(array_map(function (Shipment $record) {
            return $this->recordToShipmentArray($record);
        }, $latestByOrder));
    }

    public function markShipmentsAsSubmitted(array $orderIds, string $listId): void
    {
        foreach ($orderIds as $orderId) {
            $records = $this->db()->query(Shipment::class)
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

    public function purgeInvalidRecords(): int
    {
        $records = $this->db()->query(Shipment::class)
            ->where('submitted', '=', 0)
            ->get();

        $count = 0;
        foreach ($records as $record) {
            if (empty($record->consigneeName1) || empty($record->reference)) {
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
        return $this->db()->query(Shipment::class)
            ->where('pickupDate', '>=', $from)
            ->where('pickupDate', '<=', $to)
            ->where('submitted', '=', 1)
            ->get();
    }

    /**
     * Wandelt einen DB-Record in das Bordero-Shipment-Array um.
     * shipper_address wird nicht gespeichert – kommt immer frisch aus den Settings.
     */
    private function recordToShipmentArray(Shipment $record): array
    {
        /** @var SettingsService $settings */
        $settingsService  = pluginApp(SettingsService::class);
        $settings         = $settingsService->getSettings();

        /** @var PayloadBuilderService $builder */
        $builder          = pluginApp(PayloadBuilderService::class);
        $shipperAddress   = $builder->buildShipperAddress($settings);

        $consigneeAddress = [
            'name1'          => $record->consigneeName1,
            'name2'          => $record->consigneeName2,
            'street'         => $record->consigneeStreet,
            'zip'            => $record->consigneeZip,
            'city'           => $record->consigneeCity,
            'country'        => $record->consigneeCountry,
            'phone'          => $record->consigneePhone,
            'email'          => $record->consigneeEmail,
            'contact_person' => $record->consigneeContact,
        ];

        return [
            '_record_id'        => $record->id,
            'order_id'          => $record->orderId,
            'pickup_date'       => $record->pickupDate,
            'shipper_address'   => $shipperAddress,
            'consignee_address' => $consigneeAddress,
            'loading_address'   => $shipperAddress,
            'procurement'       => false,
            'franking'          => '1',
            'reference'         => $record->reference,
            'value'             => $record->value,
            'value_currency'    => $record->valueCurrency,
            'weight_gr'         => (int)$record->weightGr,
            'packages'          => json_decode($record->packagesJson, true) ?? [],
            'options'           => json_decode($record->optionsJson,  true) ?? [],
            'texts'             => [],
        ];
    }
}