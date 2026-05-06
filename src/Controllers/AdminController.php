<?php

namespace TranslandShipping\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;

/**
 * AdminController
 * Einfache Admin-Seite für manuelle Plugin-Aktionen.
 * Erreichbar unter: /transland/admin
 */
class AdminController extends Controller
{
    public function showDashboard(Request $request, Response $response): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TranslandShipping - Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; color: #333; padding: 24px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { font-size: 24px; margin-bottom: 8px; color: #1a1a1a; }
        .subtitle { color: #666; margin-bottom: 24px; font-size: 14px; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 24px; margin-bottom: 16px; }
        .card h2 { font-size: 18px; margin-bottom: 12px; }
        .card p { color: #666; font-size: 14px; margin-bottom: 16px; }
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover:not(:disabled) { background: #1d4ed8; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-success:hover:not(:disabled) { background: #15803d; }
        .btn-secondary { background: #6b7280; color: #fff; }
        .btn-secondary:hover:not(:disabled) { background: #4b5563; }
        .result { margin-top: 16px; padding: 12px 16px; border-radius: 6px; font-size: 13px; font-family: monospace; white-space: pre-wrap; display: none; max-height: 400px; overflow-y: auto; }
        .result.success { display: block; background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .result.error { display: block; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .result.info { display: block; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite; margin-right: 8px; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .status-badge.ok { background: #dcfce7; color: #166534; }
        .status-badge.warn { background: #fef9c3; color: #854d0e; }
        .status-badge.err { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="container">
        <h1>TranslandShipping Admin</h1>
        <p class="subtitle">Manuelle Steuerung fuer Bordero, Diagnose und Sendungsuebersicht</p>

        <div class="card">
            <h2>Bordero / Tagesabschluss</h2>
            <p>Sendet alle offenen Sendungen als Bordero an Zufall und verschickt die Ladeliste per E-Mail.</p>
            <div class="btn-row">
                <button class="btn btn-primary" id="btnPending" onclick="checkPending()">Pending Sendungen anzeigen</button>
                <button class="btn btn-success" id="btnBordero" onclick="triggerBordero()">Bordero jetzt senden</button>
            </div>
            <div class="result" id="resultPending"></div>
            <div class="result" id="resultBordero"></div>
        </div>

        <div class="card">
            <h2>Provider-Check</h2>
            <p>Prueft ob TranslandShipping als Versanddienstleister korrekt registriert ist.</p>
            <button class="btn btn-secondary" id="btnProviders" onclick="checkProviders()">Provider pruefen</button>
            <div class="result" id="resultProviders"></div>
        </div>
    </div>

    <script>
        function setLoading(btnId, loading) {
            var btn = document.getElementById(btnId);
            if (loading) {
                btn.disabled = true;
                btn.setAttribute('data-text', btn.textContent);
                btn.innerHTML = '<span class="spinner"></span> Laden...';
            } else {
                btn.disabled = false;
                btn.textContent = btn.getAttribute('data-text');
            }
        }

        function showResult(elemId, data, isError) {
            var el = document.getElementById(elemId);
            el.className = 'result ' + (isError ? 'error' : 'success');
            el.textContent = JSON.stringify(data, null, 2);
        }

        function showInfo(elemId, text) {
            var el = document.getElementById(elemId);
            el.className = 'result info';
            el.textContent = text;
        }

        function apiCall(method, url, btnId, resultId) {
            setLoading(btnId, true);
            var el = document.getElementById(resultId);
            el.className = 'result';
            el.style.display = 'none';

            var opts = { method: method, credentials: 'same-origin', headers: {} };
            if (method === 'POST') {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = '{}';
            }

            fetch(url, opts)
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    setLoading(btnId, false);
                    showResult(resultId, data, data.success === false);
                })
                .catch(function(err) {
                    setLoading(btnId, false);
                    showResult(resultId, { error: err.message }, true);
                });
        }

        function checkPending() {
            apiCall('GET', '/rest/transland/diagnostics/pending', 'btnPending', 'resultPending');
        }

        function triggerBordero() {
            if (!confirm('Bordero jetzt an Zufall senden? Alle offenen Sendungen werden uebermittelt.')) return;
            apiCall('POST', '/rest/transland/diagnostics/bordero', 'btnBordero', 'resultBordero');
        }

        function checkProviders() {
            apiCall('GET', '/rest/transland/diagnostics/providers', 'btnProviders', 'resultProviders');
        }
    </script>
</body>
</html>
HTML;

        return $response->make($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
