<?php

namespace TranslandShipping\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use TranslandShipping\Services\SettingsService;

/**
 * SettingsController
 *
 * Handles GET/POST /rest/transland/settings
 * Also serves the backend UI view.
 */
class SettingsController extends Controller
{
    private SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    public function index(): string
    {
        return pluginApp(\Plenty\Plugin\Templates\Twig::class)
            ->render('TranslandShipping::Settings.index');
    }

    /**
     * GET /rest/transland/settings
     * Returns current settings (passwords masked).
     */
    public function getSettings(Request $request, Response $response): Response
    {
        $settings = $this->settingsService->getSettings();

        // Mask sensitive values
        if (!empty($settings['password'])) {
            $settings['password'] = '••••••••';
        }

        return $response->json(['success' => true, 'settings' => $settings]);
    }

    /**
     * POST /rest/transland/settings
     * Saves settings. Ignores password field if it's the masked placeholder.
     */
    public function saveSettings(Request $request, Response $response): Response
    {
        $data = $request->all();

        // Don't overwrite password with masked placeholder
        if (($data['password'] ?? '') === '••••••••') {
            unset($data['password']);
        }

        $this->settingsService->saveSettings($data);

        return $response->json(['success' => true, 'message' => 'Einstellungen gespeichert.']);
    }
}
