<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class TranslandRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void
    {
        $router->get(
            'transland/pending',
            'TranslandShipping\Controllers\ShippingListController@getPendingShipments'
        );

        $router->post(
            'transland/submit-day',
            'TranslandShipping\Controllers\ShippingListController@submitDailyShipments'
        );

        $router->post(
            'transland/shipping-list',
            'TranslandShipping\Controllers\ShippingListController@submitShippingList'
        );

        $router->post(
            'transland/label',
            'TranslandShipping\Controllers\LabelController@createLabel'
        );
    }
}