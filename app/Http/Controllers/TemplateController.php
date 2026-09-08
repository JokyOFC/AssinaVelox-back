<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Modelos — placeholder Fase 2 (ROUTES §1.2 / §2.21). Extension point: features.templates.
 */
class TemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('templates/index', [
            'feature' => 'templates',
            'title' => 'Modelos de documentos',
            'subtitle' => 'Reaproveite contratos e formulários recorrentes com campos já posicionados.',
        ]);
    }
}
