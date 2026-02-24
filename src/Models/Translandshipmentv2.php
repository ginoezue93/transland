<?php

namespace TranslandShipping\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * @property int    $id
 * @property int    $orderId
 * @property string $pickupDate
 * @property string $listId
 * @property int    $submitted
 * @property string $shipmentData
 * @property string $createdAt
 * @property string $updatedAt
 *
 * @Nullable(columns={"listId"})
 * @Column(name="shipmentData", type="text")
 */
class TranslandShipmentV2 extends Model
{
    protected $primaryKey = 'id';

    public $id           = 0;
    public $orderId      = 0;
    public $pickupDate   = '';
    public $listId       = '';
    public $submitted    = 0;
    public $shipmentData = '';
    public $createdAt    = '';
    public $updatedAt    = '';

    public function getTableName(): string
    {
        return 'TranslandShipping::ShipmentV2';
    }
}