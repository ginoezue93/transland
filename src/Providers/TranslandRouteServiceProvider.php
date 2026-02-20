<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;
use Plenty\Plugin\Routing\ApiRouter;

class TranslandRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void
    {
        // Backend UI routes
        $router->get(
            'transland/settings',
            'TranslandShipping\Controllers\SettingsController@index'
        );
    }

    public function mapApi(ApiRouter $apiRouter): void
    {
        // REST API routes for Plenty process automation
        $apiRouter->version(
            ['v1'],
            ['namespace' => 'TranslandShipping\Controllers', 'middleware' => 'oauth'],
            function (ApiRouter $apiRouter) {
                // Label endpoint - called during packing process
                $apiRouter->post('transland/label', 'LabelController@createLabel');
                
                // Shipping list endpoint - called at end of day
                $apiRouter->post('transland/shipping-list', 'ShippingListController@submitShippingList');
                
                // Get all pending (label-printed, not yet submitted) shipments
                $apiRouter->get('transland/pending', 'ShippingListController@getPendingShipments');
                
                // Manually trigger daily shipping list submission
                $apiRouter->post('transland/submit-day', 'ShippingListController@submitDailyShipments');

                // Settings CRUD
                $apiRouter->get('transland/settings', 'SettingsController@getSettings');
                $apiRouter->post('transland/settings', 'SettingsController@saveSettings');
            }
        );
    }
}
