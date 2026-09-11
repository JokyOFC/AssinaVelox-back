<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Retention\RetentionFeature;
use App\Services\Retention\RetentionPolicies;
use App\Services\Retention\RetentionPresenter;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Retenção e preservação (Fase 2 §2.19 — docs/fase-2/retencao-e-preservacao.md).
 *
 * Autorização: `updateSettings` (permissão `manage_settings`). Flag `retention_policies`
 * desligada: o GET mostra o estado "Fase 2" e a gravação responde 404.
 */
class RetentionController extends Controller
{
    public function __construct(
        private readonly RetentionPolicies $policies,
        private readonly RetentionPresenter $presenter,
    ) {}

    public function edit(Request $request): Response
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        abort_if($organization === null, 404);
        Gate::authorize('updateSettings', $organization);

        return Inertia::render('settings/retention', $this->presenter->settings($organization, $current->membership()));
    }

    /**
     * Prévia da exclusão com os prazos do formulário (nada é apagado): quantos itens a próxima
     * execução apagaria e quando ela roda. Usada na confirmação antes de salvar.
     */
    public function preview(Request $request): JsonResponse
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        abort_if($organization === null, 404);
        Gate::authorize('updateSettings', $organization);
        RetentionFeature::ensure($organization);

        $periods = $request->input('periods');

        return response()->json($this->presenter->deletionPreview(
            $organization,
            $this->policies->forOrganization($organization),
            is_array($periods) ? $periods : [],
        ));
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        abort_if($organization === null, 404);
        Gate::authorize('updateSettings', $organization);
        RetentionFeature::ensure($organization);

        /** @var User $user */
        $user = $request->user();

        $policy = $this->policies->save($organization, $user, [
            'is_active' => $request->boolean('is_active'),
            'periods' => is_array($request->input('periods')) ? $request->input('periods') : [],
            'confirmation' => $request->input('confirmation'),
        ]);

        return back()->with('success', $policy->is_active
            ? 'Política de retenção salva. A exclusão automática roda uma vez por dia.'
            : 'Política de retenção salva. A exclusão automática está desativada.');
    }
}
