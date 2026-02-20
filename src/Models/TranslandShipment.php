<?php

namespace TranslandShipping\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * TranslandShipment Model
 *
 * Stores shipment data between label printing and Bordero submission.
 *
 * @property int         $id
 * @property int         $orderId
 * @property string      $pickupDate
 * @property string|null $listId
 * @property bool        $submitted
 * @property string      $shipmentData   JSON blob with full shipment payload
 * @property string      $createdAt
 * @property string      $updatedAt
 */
class TranslandShipment extends Model
{
    /** @var string */
    public $primaryKeyFieldName = 'id';

    /** @var string */
    public $primaryKeyFieldType = 'int';

    /** @var bool */
    public $autoIncrementPrimaryKey = true;

    /** @var string */
    protected $table = 'TranslandShipping_shipments';

    public int     $id           = 0;
    public int     $orderId      = 0;
    public string  $pickupDate   = '';
    public ?string $listId       = null;
    public bool    $submitted    = false;
    public string  $shipmentData = '';
    public string  $createdAt    = '';
    public string  $updatedAt    = '';

    public function getTableName(): string
    {
        return $this->table;
    }
}
