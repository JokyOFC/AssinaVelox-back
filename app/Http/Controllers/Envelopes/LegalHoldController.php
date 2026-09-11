<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\LegalHold;
use App\Models\User;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use App\Services\Retention\RetentionAuthorization;
use App\Services\Retention\RetentionFeature;
use App\Services\Retention\RetentionPresenter;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Bloqueio de exclusão por preservação (Fase 2 §2.19).
 *
 *  - `envelopes.legal_hold.show` (GET, JSON): contrato do detalhe do documento — selo
 *    "Preservado", bloqueios que o cobrem e o que a pessoa pode fazer. Só `view`.
 *  - `envelopes.legal_hold.store`: preserva um documento.
 *  - `settings.retention.holds.store`: preserva uma pasta ou a organização inteira.
 *  - `legal_holds.release`: libera (motivo obrigatório, fica na trilha).
 *
 * Criar exige a flag `retention_policies`; liberar e consultar não — um bloqueio já criado
 * não pode ficar impossível de liberar porque a flag foi desligada. Todas exigem a capacidade
 * `manage_legal_holds` ({@see RetentionAuthorization::canManageHolds()}), exceto a consulta.
 */
class LegalHoldController extends Controller
{
    public function __construct(
        private readonly LegalHolds $holds,
        private readonly RetentionPresenter $presenter,
    ) {}

    public function show(Request $request, Envelope $envelope): JsonResponse
    {
        Gate::authorize('view', $envelope);

        return response()->json($this->presenter->forEnvelope($envelope, CurrentOrganization::instance()->membership()));
    }

    public function storeForEnvelope(Request $request, Envelope $envelope): RedirectResponse
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        abort_if($organization === null, 404);
        RetentionFeature::ensure($organization);
        Gate::authorize('view', $envelope);
        abort_unless(RetentionAuthorization::canManageHolds($current->membership()), 403);

        $validated = $this->validateHold($request);

        /** @var User $user */
        $user = $request->user();

        $this->holds->place($organization, $user, LegalHoldScope::Envelope, $validated['reason'], envelope: $envelope, endsAt: $validated['ends_at']);

        return back()->with('success', 'Documento preservado. Ele não pode ser excluído por ninguém até a liberação.');
    }

    public function store(Request $request): RedirectResponse
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        abort_if($organization === null, 404);
        RetentionFeature::ensure($organization);
        abort_unless(RetentionAuthorization::canManageHolds($current->membership()), 403);

        $request->validate([
            'scope' => ['required', Rule::in([LegalHoldScope::Folder->value, LegalHoldScope::Organization->value])],
            'folder_id' => [
                'nullable',
                'required_if:scope,'.LegalHoldScope::Folder->value,
                'string',
                'size:26',
                Rule::exists('folders', 'ulid')->where('organization_id', $organization->getKey()),
            ],
        ], [
            'folder_id.required_if' => 'Escolha a pasta a preservar.',
            'folder_id.exists' => 'Pasta não encontrada.',
        ], ['scope' => 'abrangência', 'folder_id' => 'pasta']);

        $validated = $this->validateHold($request);
        $scope = LegalHoldScope::from((string) $request->input('scope'));

        $folder = $scope === LegalHoldScope::Folder
            ? Folder::forOrganization($organization)->where('ulid', (string) $request->input('folder_id'))->firstOrFail()
            : null;

        /** @var User $user */
        $user = $request->user();

        $this->holds->place($organization, $user, $scope, $validated['reason'], folder: $folder, endsAt: $validated['ends_at']);

        return back()->with('success', $scope === LegalHoldScope::Organization
            ? 'Organização preservada. Nenhum documento pode ser excluído até a liberação.'
            : 'Pasta preservada. Os documentos dela (e das subpastas) não podem ser excluídos até a liberação.');
    }

    public function release(Request $request, LegalHold $legalHold): RedirectResponse
    {
        abort_unless(RetentionAuthorization::canManageHolds(CurrentOrganization::instance()->membership()), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:'.LegalHolds::MAX_REASON],
        ], [], ['reason' => 'motivo da liberação']);

        if (! $legalHold->isActive()) {
            return back()->with('info', 'Esta preservação já não está ativa.');
        }

        /** @var User $user */
        $user = $request->user();

        $this->holds->release($legalHold, $user, $validated['reason']);

        return back()->with('success', 'Preservação liberada. A liberação ficou registrada na trilha.');
    }

    /**
     * @return array{reason: string, ends_at: Carbon|null}
     */
    private function validateHold(Request $request): array
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:'.LegalHolds::MAX_REASON],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ], [
            'ends_at.after' => 'A data final precisa estar no futuro.',
        ], ['reason' => 'motivo', 'ends_at' => 'preservar até']);

        return [
            'reason' => (string) $validated['reason'],
            'ends_at' => isset($validated['ends_at']) ? Carbon::parse((string) $validated['ends_at']) : null,
        ];
    }
}
