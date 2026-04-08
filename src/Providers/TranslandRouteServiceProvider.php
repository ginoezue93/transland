<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;
use Plenty\Plugin\Routing\ApiRouter;

class TranslandRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void { }

    public function mapApi(ApiRouter $apiRouter): void
    {
        $apiRouter->version(
            ['v1'],
            ['namespace' => 'TranslandShipping\\Controllers', 'middleware' => 'oauth'],
            function (ApiRouter $apiRouter) {
                // Diagnose-Endpoints (fuer Debugging)
                $apiRouter->get('transland/diagnostics/providers',            'DiagnosticsController@listProviders');
                $apiRouter->post('transland/diagnostics/register/{orderId}',  'DiagnosticsController@triggerRegister');

                // Bordero-Endpoints
                $apiRouter->post('transland/submit-day',    'ShippingListController@submitDailyShipments');
                $apiRouter->get('transland/pending',        'ShippingListController@getPendingShipments');
                $apiRouter->post('transland/shipping-list', 'ShippingListController@submitShippingList');
            }
        );
    }
}
