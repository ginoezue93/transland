<?php

namespace TranslandShipping\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;
use TranslandShipping\Services\SettingsService;

class AdminController extends Controller
{
    use Loggable;

    private function validateToken(Request $request): bool
    {
        /** @var SettingsService $settingsService */
        $settingsService = pluginApp(SettingsService::class);
        $settings = $settingsService->getSettings();
        $configToken = trim((string)($settings['webhook_token'] ?? ''));

        if ($configToken === '' || strlen($configToken) < 8) {
            return false;
        }

        $requestToken = trim((string)$request->get('token', ''));
        return $requestToken === $configToken;
    }

    public function webhookBordero(Request $request, Response $response): Response
    {
        if (!$this->validateToken($request)) {
            return $response->json([
                'success' => false,
                'message' => 'Invalid or missing token.',
            ], 403);
        }

        try {
            $this->getLogger(__CLASS__)->error('TranslandShipping::webhook.borderoTriggered', [
                'time'   => date('Y-m-d H:i:s'),
                'source' => 'webhook',
            ]);

            /** @var SettingsService $settingsService */
            $settingsService = pluginApp(SettingsService::class);
            $settings = $settingsService->getSettings();
            $returnList = (bool)($settings['return_ladeliste_pdf'] ?? true);

            /** @var \TranslandShipping\Services\ShippingListService $shippingListService */
            $shippingListService = pluginApp(\TranslandShipping\Services\ShippingListService::class);
            $result = $shippingListService->submitDailyShipments('', $returnList);

            return $response->json([
                'success'        => true,
                'result'         => $result['result'] ?? 'unknown',
                'shipment_count' => $result['shipment_count'] ?? 0,
                'list_id'        => $result['list_id'] ?? '',
            ]);

        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function webhookPending(Request $request, Response $response): Response
    {
        if (!$this->validateToken($request)) {
            return $response->json([
                'success' => false,
                'message' => 'Invalid or missing token.',
            ], 403);
        }

        try {
            /** @var \TranslandShipping\Services\StorageService $storageService */
            $storageService = pluginApp(\TranslandShipping\Services\StorageService::class);
            $pending = $storageService->getPendingShipments('');

            return $response->json([
                'success'   => true,
                'count'     => count($pending),
                'shipments' => array_map(function ($s) {
                    return [
                        'orderId'      => $s['order_id'] ?? 0,
                        'reference'    => $s['reference'] ?? '',
                        'pickupDate'   => $s['pickup_date'] ?? '',
                        'weightGr'     => $s['weight_gr'] ?? 0,
                        'labelPrinted' => $s['label_printed'] ?? 0,
                        'submitted'    => $s['submitted'] ?? 0,
                    ];
                }, $pending),
            ]);

        } catch (\Exception $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function showDashboard(Request $request, Response $response): Response
    {
        /** @var SettingsService $settingsService */
        $settingsService = pluginApp(SettingsService::class);
        $settings = $settingsService->getSettings();
        $token = trim((string)($settings['webhook_token'] ?? ''));
        $tokenOk = ($token !== '' && strlen($token) >= 8);
        $tp = $tokenOk ? '?token=' . $token : '';
        $host = $_SERVER['HTTP_HOST'] ?? 'DEINE-DOMAIN.my.plentysystems.com';
        $webhookUrl = $tokenOk ? ('https://' . $host . '/transland/webhook/bordero' . $tp) : 'Token zuerst in Plugin-Einstellungen konfigurieren!';

        $warn = $tokenOk ? '' : '<div style="background:#fef9c3;border:1px solid #fde68a;color:#854d0e;padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:14px;">Webhook-Token nicht konfiguriert. Bitte unter Plugin-Einstellungen &rarr; Etikett &amp; Tagesabschluss eintragen (min. 8 Zeichen).</div>';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>TranslandShipping Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;color:#333;padding:24px}.c{max-width:800px;margin:0 auto}h1{font-size:24px;margin-bottom:8px}.sub{color:#666;margin-bottom:24px;font-size:14px}.card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:24px;margin-bottom:16px}.card h2{font-size:18px;margin-bottom:12px}.card p{color:#666;font-size:14px;margin-bottom:16px}.btn{display:inline-block;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer}.btn:disabled{opacity:.5;cursor:not-allowed}.bp{background:#2563eb;color:#fff}.bp:hover:not(:disabled){background:#1d4ed8}.bs{background:#16a34a;color:#fff}.bs:hover:not(:disabled){background:#15803d}.r{margin-top:16px;padding:12px 16px;border-radius:6px;font-size:13px;font-family:monospace;white-space:pre-wrap;display:none;max-height:400px;overflow-y:auto}.r.ok{display:block;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.r.er{display:block;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.sp{display:inline-block;width:16px;height:16px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:s .6s linear infinite;margin-right:8px;vertical-align:middle}@keyframes s{to{transform:rotate(360deg)}}.br{display:flex;gap:8px;flex-wrap:wrap}.code{background:#f3f4f6;padding:8px 12px;border-radius:4px;font-family:monospace;font-size:13px;word-break:break-all;margin:8px 0}
</style>
</head>
<body>
<div class="c">
<h1>TranslandShipping Admin</h1>
<p class="sub">Bordero / Tagesabschluss und Diagnose</p>
{$warn}
<div class="card">
<h2>Bordero / Tagesabschluss</h2>
<p>Sendet alle offenen Sendungen als Bordero an Zufall und verschickt die Ladeliste per E-Mail.</p>
<div class="br">
<button class="btn bp" id="bp" onclick="gp()">Pending Sendungen</button>
<button class="btn bs" id="bb" onclick="gb()">Bordero jetzt senden</button>
</div>
<div class="r" id="rp"></div>
<div class="r" id="rb"></div>
</div>
<div class="card">
<h2>Webhook-URL fuer externen Cron (z.B. cron-job.org)</h2>
<p>Diese URL taeglich um 12:00 als GET-Request aufrufen lassen:</p>
<div class="code">{$webhookUrl}</div>
</div>
</div>
<script>
var T='{$tp}';
function sl(id,l){var b=document.getElementById(id);if(l){b.disabled=true;b.setAttribute('dt',b.textContent);b.innerHTML='<span class="sp"></span>Laden...';}else{b.disabled=false;b.textContent=b.getAttribute('dt');}}
function sr(id,d,e){var el=document.getElementById(id);el.className='r '+(e?'er':'ok');el.textContent=JSON.stringify(d,null,2);}
function gp(){sl('bp',1);fetch('/transland/webhook/pending'+T).then(function(r){return r.json();}).then(function(d){sl('bp',0);sr('rp',d,d.success===false);}).catch(function(e){sl('bp',0);sr('rp',{error:e.message},1);});}
function gb(){if(!confirm('Bordero jetzt an Zufall senden?'))return;sl('bb',1);fetch('/transland/webhook/bordero'+T).then(function(r){return r.json();}).then(function(d){sl('bb',0);sr('rb',d,d.success===false);}).catch(function(e){sl('bb',0);sr('rb',{error:e.message},1);});}
</script>
</body>
</html>
HTML;

        return $response->make($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
