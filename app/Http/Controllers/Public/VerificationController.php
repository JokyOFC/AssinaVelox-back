<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verificação pública (ROUTES §1.4 / §4). Esqueleto com o contrato de props —
 * // TODO(Wave C): consultar verification_records pelo código (sem PDF, CPF, e-mail ou IP).
 */
class VerificationController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate(['code' => ['nullable', 'string', 'max:20']]);

        return Inertia::render('verify/index', [
            'code' => $validated['code'] ?? null,
            'error' => $request->session()->get('error'),
        ]);
    }

    public function show(Request $request, string $code): Response
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return Inertia::render('verify/show', [
            'code' => $normalized,
            'found' => false,
            'result' => null,
            'file_check' => null,
        ]);
    }

    public function checkFile(Request $request, string $code): RedirectResponse
    {
        $request->validate([
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ], [], ['sha256' => 'hash do arquivo']);

        // TODO(Wave C): comparar com final_sha256 / sent_sha256 do registro de verificação.
        return back();
    }
}
