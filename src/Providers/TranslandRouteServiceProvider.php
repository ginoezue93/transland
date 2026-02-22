<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;
use Plenty\Plugin\Routing\ApiRouter;

class TranslandRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void
    {
        // no frontend routes needed
    }

    public function mapApi(ApiRouter $apiRouter): void
    {
        $apiRouter->version(
            ['v1'],
            ['namespace' => 'TranslandShipping\Controllers', 'middleware' => 'oauth'],
            function (ApiRouter $apiRouter) {
                // Label-Druck beim Verpacken
                $apiRouter->post('transland/label', 'LabelController@createLabel');

                // Tagesabschluss / Bordero
                $apiRouter->post('transland/submit-day', 'ShippingListController@submitDailyShipments');
                $apiRouter->get('transland/pending', 'ShippingListController@getPendingShipments');
                $apiRouter->post('transland/shipping-list', 'ShippingListController@submitShippingList');
            }
        );
    }
}
