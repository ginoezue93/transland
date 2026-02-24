<?php

namespace TranslandShipping\Migrations;

use TranslandShipping\Models\TranslandShipmentV3;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;

class CreateShipmentTableV2
{
    public function run(Migrate $migrate): void
    {
        $migrate->createTable(TranslandShipmentV3::class);
    }
}