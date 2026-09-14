<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Risk\RiskFeature;
use App\Services\Risk\RiskPrecisionReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel interno › Antifraude › Precisão por regra (roadmap §3.7, "relatório mensal de
 * precisão"). Casos confirmados × liberados por regra, para ajustar limiares e pontuações.
 */
class RiskReportController extends Controller
{
    public function precision(Request $request, RiskPrecisionReport $report): Response
    {
        abort_unless(RiskFeature::enabled(), 404);

        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return Inertia::render('admin/risk/precision', [
            'report' => $report->build($validated['month'] ?? null),
        ]);
    }
}
