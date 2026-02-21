<?php

namespace TranslandShipping\Migrations;

use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;

class CreateTranslandShipmentTable
{
    public function run(Migrate $migrate): void
    {
        $migrate->createTable('TranslandShipping::TranslandShipment');
    }
}
