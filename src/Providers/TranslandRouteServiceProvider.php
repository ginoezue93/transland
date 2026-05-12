<?php

namespace TranslandShipping\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;
use Plenty\Plugin\Routing\ApiRouter;

class TranslandRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void
    {
        // Admin-Seite für manuellen Bordero-Trigger
        $router->get('transland/admin', 'TranslandShipping\\Controllers\\AdminController@showDashboard');

        // Webhook für externen Cron-Dienst (z.B. cron-job.org)
        // Aufruf: GET https://domain.com/transland/webhook/bordero?token=DEIN_TOKEN
        // Kein Login nötig — Token schützt vor unbefugtem Zugriff.
        $router->get('transland/webhook/bordero', 'TranslandShipping\\Controllers\\AdminController@webhookBordero');

        // Pending Sendungen anzeigen (für Admin-Seite)
        $router->get('transland/webhook/pending', 'TranslandShipping\\Controllers\\AdminController@webhookPending');
    }

    public function mapApi(ApiRouter $apiRouter): void
    {
        $apiRouter->version(
            ['v1'],
            ['namespace' => 'TranslandShipping\\Controllers', 'middleware' => 'oauth'],
            function (ApiRouter $apiRouter) {
                // Diagnose-Endpoints (fuer Debugging)
                $apiRouter->get('transland/diagnostics/providers',            'DiagnosticsController@listProviders');
                $apiRouter->post('transland/diagnostics/register/{orderId}',  'DiagnosticsController@triggerRegister');
                $apiRouter->post('transland/diagnostics/bordero',             'DiagnosticsController@triggerBordero');
                $apiRouter->get('transland/diagnostics/pending',              'DiagnosticsController@listPending');

                // Bordero-Endpoints
                $apiRouter->post('transland/submit-day',    'ShippingListController@submitDailyShipments');
                $apiRouter->get('transland/pending',        'ShippingListController@getPendingShipments');
                $apiRouter->post('transland/shipping-list', 'ShippingListController@submitShippingList');
            }
        );
    }
}
