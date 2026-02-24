<?php

namespace TranslandShipping\Migrations;

use TranslandShipping\Models\Shipment;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;

class CreateShipmentTableV4
{
    public function run(Migrate $migrate): void
    {
        // Alte Tabelle löschen (alle alten Daten sind ungültig)
        $migrate->deleteTable(Shipment::class);

        // Neu anlegen mit korrekten Spalten
        $migrate->createTable(Shipment::class);
    }
}