<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Models\Delegation;
use App\Models\Envelope;
use App\Models\User;
use App\Services\Envelopes\Delegation\DelegationException;
use App\Services\Envelopes\Delegation\DelegationExecutor;
use App\Services\Envelopes\Delegation\DelegationPolicy;
use App\Services\Envelopes\Steps\FlowFeatures;
use App\Services\Envelopes\Steps\FlowState;
use App\Services\Envelopes\Steps\SigningStepPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Etapas condicionais e delegação do lado de QUEM ENVIA (Fase 3 §3.3, F-FLOW —
 * docs/fase-3/etapas-e-delegacao.md §5).
 *
 * - `GET envelopes.flow.show` (JSON, `view`): estado do fluxo para o wizard e o detalhe. 404 com
 *   as flags `conditional_steps` e `delegation` desligadas (e nada gravado no envelope).
 * - `PUT envelopes.steps.update` / `envelopes.delegation.update` (JSON, `update`, só em preparo).
 * - `POST envelopes.delegations.approve|reject` (`send`, coleta em andamento): redireciona de
 *   volta com flash, como as demais ações do detalhe.
 */
class EnvelopeFlowController extends Controller
{
    public function __construct(
        private readonly FlowState $state,
        private readonly SigningStepPlan $plan,
        private readonly DelegationExecutor $executor,
    ) {}

    public function show(Request $request, Envelope $envelope): JsonResponse
    {
        Gate::authorize('view', $envelope);

        $state = $this->state->forEnvelope($envelope, $this->user($request));

        abort_if($state === null, 404);

        return response()->json($state);
    }

    public function updateSteps(Request $request, Envelope $envelope): JsonResponse
    {
        // Com a flag desligada, só DESFAZER etapas gravadas antes (rascunho que ficou com
        // `uses_signing_steps`) passa: desligar não cria nada novo (revisão adversarial da onda F).
        $flagOn = FlowFeatures::conditionalSteps($envelope->organization);
        abort_unless($flagOn || ($request->input('enabled') === false && $envelope->usesSigningSteps()), 404);
        Gate::authorize('update', $envelope);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'steps' => ['nullable', 'array'],
        ], [], ['enabled' => 'etapas', 'steps' => 'etapas']);

        $this->plan->update($envelope, (bool) $validated['enabled'], $request->input('steps'));

        if (! $flagOn) {
            return response()->json(['steps' => ['enabled' => false]]);
        }

        return response()->json($this->state->forEnvelope($envelope->refresh(), $this->user($request)));
    }

    public function updateDelegation(Request $request, Envelope $envelope): JsonResponse
    {
        abort_unless(FlowFeatures::delegation($envelope->organization), 404);
        Gate::authorize('update', $envelope);

        $validated = $request->validate([
            'allow' => ['required', 'boolean'],
            'requires_confirmation' => ['required', 'boolean'],
            'personal' => ['present', 'array', 'max:100'],
            'personal.*' => ['string', 'size:26'],
        ], [], ['allow' => 'delegação', 'requires_confirmation' => 'confirmação', 'personal' => 'participantes pessoais']);

        /** @var list<string> $personal */
        $personal = array_values(array_map('strval', (array) ($validated['personal'] ?? [])));

        DelegationPolicy::update($envelope, [
            'allow' => (bool) $validated['allow'],
            'requires_confirmation' => (bool) $validated['requires_confirmation'],
            'personal' => $personal,
        ]);

        return response()->json($this->state->forEnvelope($envelope->refresh(), $this->user($request)));
    }

    public function approve(Request $request, Envelope $envelope, string $delegation): RedirectResponse
    {
        abort_unless(FlowFeatures::delegation($envelope->organization), 404);
        Gate::authorize('send', $envelope);

        $model = $this->find($envelope, $delegation);

        try {
            $delegate = $this->executor->execute($model, $this->user($request));
        } catch (DelegationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf('Delegação confirmada. %s recebeu o convite para participar.', $delegate->name));
    }

    public function reject(Request $request, Envelope $envelope, string $delegation): RedirectResponse
    {
        abort_unless(FlowFeatures::delegation($envelope->organization), 404);
        Gate::authorize('send', $envelope);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['note' => 'observação']);

        $model = $this->find($envelope, $delegation);
        $user = $this->user($request);
        abort_if($user === null, 403);

        try {
            $this->executor->reject($model, $user, isset($validated['note']) ? (string) $validated['note'] : null);
        } catch (DelegationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Pedido de delegação recusado. O participante original continua no documento.');
    }

    private function find(Envelope $envelope, string $ulid): Delegation
    {
        /** @var Delegation */
        return Delegation::withoutOrganizationScope()
            ->where('organization_id', $envelope->organization_id)
            ->where('envelope_id', $envelope->getKey())
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
