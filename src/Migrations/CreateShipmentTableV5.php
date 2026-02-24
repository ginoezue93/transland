<?php

namespace TranslandShipping\Migrations;

use TranslandShipping\Models\Shipment;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;

class CreateShipmentTableV5
{
    public function run(Migrate $migrate): void
    {
        $migrate->deleteTable(Shipment::class);
        $migrate->createTable(Shipment::class);
    }
}