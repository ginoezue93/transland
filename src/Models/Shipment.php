<?php

namespace TranslandShipping\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * @property int    $id
 * @property int    $orderId
 * @property string $pickupDate
 * @property string $listId
 * @property int    $submitted
 *
 * Consignee
 * @property string $consigneeName1
 * @property string $consigneeName2
 * @property string $consigneeStreet
 * @property string $consigneeZip
 * @property string $consigneeCity
 * @property string $consigneeCountry
 * @property string $consigneePhone
 * @property string $consigneeEmail
 * @property string $consigneeContact
 *
 * Shipment details
 * @property string $reference
 * @property string $value
 * @property string $valueCurrency
 * @property int    $weightGr
 *
 * Complex fields as JSON (small)
 * @property string $packagesJson
 * @property string $optionsJson
 *
 * @property string $createdAt
 * @property string $updatedAt
 *
 * @Nullable(columns={"listId","consigneeName2","consigneePhone","consigneeEmail","consigneeContact"})
 */
class Shipment extends Model
{
    protected $primaryKey = 'id';

    public $id               = 0;
    public $orderId          = 0;
    public $pickupDate       = '';
    public $listId           = '';
    public $submitted        = 0;

    // Consignee
    public $consigneeName1   = '';
    public $consigneeName2   = '';
    public $consigneeStreet  = '';
    public $consigneeZip     = '';
    public $consigneeCity    = '';
    public $consigneeCountry = '';
    public $consigneePhone   = '';
    public $consigneeEmail   = '';
    public $consigneeContact = '';

    // Shipment details
    public $reference        = '';
    public $value            = '0';
    public $valueCurrency    = 'EUR';
    public $weightGr         = 0;

    // Complex fields
    public $packagesJson     = '[]';
    public $optionsJson      = '[]';

    public $createdAt        = '';
    public $updatedAt        = '';

    public function getTableName(): string
    {
        return 'TranslandShipping::ShipmentTable';
    }
}