<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Services\Dossier\DossierException;
use App\Services\Dossier\DossierExports;
use App\Services\Dossier\DossierFeature;
use App\Services\Dossier\Models\DossierExport;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pedido e status do dossiê ZIP (roadmap §2.13; "Baixar" em lote Q12).
 *
 * - `store` (detalhe do envelope) e `storeBulk` (seleção da listagem) criam — ou reaproveitam,
 *   idempotente — o pedido e o põem na fila. JSON (202) para quem pede JSON; senão volta com
 *   flash;
 * - `show` devolve o status em JSON (polling do front) com o link assinado quando pronto.
 *
 * Flag `dossier_export` desligada: 404. Envelope ou pedido de outra organização: 404 (binding
 * escopado); sem permissão sobre o envelope: 403 no pedido, 404 no status.
 */
class DossierExportController extends Controller
{
    public function __construct(private readonly DossierExports $exports) {}

    public function store(Request $request, Envelope $envelope): JsonResponse|RedirectResponse
    {
        abort_unless(DossierFeature::enabled(CurrentOrganization::instance()->get()), 404);
        Gate::authorize('download', $envelope);

        try {
            $export = $this->exports->requestSingle($envelope, $request->user());
        } catch (DossierException $exception) {
            return $this->failure($request, $exception);
        }

        return $this->accepted($request, $export);
    }

    public function storeBulk(Request $request): JsonResponse|RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        abort_unless(DossierFeature::enabled($organization), 404);

        $max = max(1, (int) config('assinavelox.dossier.max_bulk_envelopes', 50));

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'ids.*' => ['string', 'size:26'],
        ], [], ['ids' => 'documentos']);

        $membership = CurrentOrganization::instance()->membership();
        abort_if($membership === null, 403);

        try {
            $export = $this->exports->requestBulk($membership, $request->user(), array_values(array_unique($validated['ids'])));
        } catch (DossierException $exception) {
            return $this->failure($request, $exception);
        }

        return $this->accepted($request, $export);
    }

    public function show(Request $request, DossierExport $dossierExport): JsonResponse
    {
        abort_unless(DossierFeature::enabled(CurrentOrganization::instance()->get()), 404);
        abort_unless($this->exports->canAccess($request->user(), $dossierExport), 404);

        return response()->json(['export' => $this->exports->statusProps($dossierExport)])
            ->header('Cache-Control', 'no-store, private');
    }

    private function accepted(Request $request, DossierExport $export): JsonResponse|RedirectResponse
    {
        $props = $this->exports->statusProps($export);

        if ($request->wantsJson()) {
            return response()->json(['export' => $props], $export->isReady() ? 200 : 202);
        }

        return back()
            ->with($export->isReady() ? 'success' : 'info', $export->isReady()
                ? 'O dossiê está pronto para baixar.'
                : 'Estamos preparando o dossiê. O link de download aparece quando ficar pronto.')
            ->with('dossier_export', $props);
    }

    private function failure(Request $request, DossierException $exception): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], 422);
        }

        return back()->with('error', $exception->getMessage());
    }
}
