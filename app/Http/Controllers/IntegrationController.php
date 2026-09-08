<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API e integrações — placeholder Fase 2 (ROUTES §1.2 / §2.21). `keys` e `logs` redirecionam
 * (302) para o índice na Fase 1. Extension point: features.api_integrations (/api/v1, Sanctum).
 */
class IntegrationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('integrations/index', [
            'feature' => 'api_integrations',
            'title' => 'API e integrações',
            'subtitle' => 'Automatize envios e receba eventos por webhooks.',
            'support_email' => (string) config('assinavelox.support_email'),
        ]);
    }

    public function keys(): RedirectResponse
    {
        return redirect()->route('integrations.index');
    }

    public function logs(): RedirectResponse
    {
        return redirect()->route('integrations.index');
    }
}
