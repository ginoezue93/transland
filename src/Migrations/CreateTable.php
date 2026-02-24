<?php

namespace TranslandShipping\Migrations;

use TranslandShipping\Models\Shipment;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;

class CreateTable
{
    public function run(Migrate $migrate): void
    {
        $migrate->createTable(Shipment::class);
    }
}