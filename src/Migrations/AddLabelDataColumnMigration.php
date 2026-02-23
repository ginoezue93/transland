<?php

namespace TranslandShipping\Migrations;

use TranslandShipping\Models\TranslandShipment;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;

class CreateShipmentTable
{
    public function run(Migrate $migrate): void
    {
        $migrate->createTable(TranslandShipment::class);
    }
}