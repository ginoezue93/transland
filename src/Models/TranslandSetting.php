<?php

namespace TranslandShipping\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * TranslandSetting Model
 *
 * Key-value store for plugin settings.
 *
 * @property int    $id
 * @property string $settingKey
 * @property string $settingValue
 * @property string $updatedAt
 */
class TranslandSetting extends Model
{
    public $primaryKeyFieldName       = 'id';
    public $primaryKeyFieldType       = 'int';
    public $autoIncrementPrimaryKey   = true;

    protected $table = 'TranslandShipping_settings';

    public int    $id           = 0;
    public string $settingKey   = '';
    public string $settingValue = '';
    public string $updatedAt    = '';

    public function getTableName(): string
    {
        return $this->table;
    }
}
