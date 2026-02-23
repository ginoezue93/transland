<?php

namespace TranslandShipping\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * @property int    $id
 * @property int    $orderId
 * @property string $pickupDate
 * @property string $listId
 * @property bool   $submitted
 * @property string $shipmentData
 * @property string $labelData
 * @property string $createdAt
 * @property string $updatedAt
 *
 * @Nullable(columns={"listId", "labelData"})
 */
class TranslandShipment extends Model
{
    public $id           = 0;
    public $orderId      = 0;
    public $pickupDate   = '';
    public $listId       = '';
    public $submitted    = false;
    public $shipmentData = '';
    public $labelData    = '';   // base64 PDF label
    public $createdAt    = '';
    public $updatedAt    = '';

    public function getTableName(): string
    {
        return 'TranslandShipping::Shipment';
    }
}