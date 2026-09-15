<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 3 §3.3 — F-FLOW (etapas condicionais e delegação)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes. Monta rascunhos pelo
| mesmo caminho da interface (PUT etapas/delegação, POST enviar) e captura os tokens dos
| convites como o e-mail os entregaria.
*/

use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\SigningOrder;
use App\Models\Organization;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

if (! function_exists('flowEnableFlags')) {
    /**
     * Liga `conditional_steps` e `delegation` (config global E plano) e as flags de papéis.
     */
    function flowEnableFlags(Organization $organization, bool $steps = true, bool $delegation = true): void
    {
        domainEnableFlags($organization);

        config()->set('assinavelox.features.conditional_steps', $steps);
        config()->set('assinavelox.features.delegation', $delegation);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['conditional_steps'] = $steps;
        $features['delegation'] = $delegation;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('flowCaptureInvites')) {
    /**
     * Guarda o token do convite MAIS RECENTE de cada e-mail e quantos convites cada um recebeu.
     *
     * @param  ArrayObject<string, string>  $tokens
     * @param  ArrayObject<string, int>  $counts
     */
    function flowCaptureInvites(ArrayObject $tokens, ArrayObject $counts): void
    {
        Event::listen(NotificationSending::class, function (NotificationSending $event) use ($tokens, $counts): void {
            if (! $event->notification instanceof RecipientInvitationNotification) {
                return;
            }

            $email = $event->notification->recipient->email;
            $segments = array_values(array_filter(explode('/', (string) parse_url($event->notification->signingUrl, PHP_URL_PATH))));

            $tokens[$email] = (string) end($segments);
            $counts[$email] = ($counts[$email] ?? 0) + 1;
        });
    }
}

if (! function_exists('flowDraft')) {
    /**
     * Rascunho com documento, participantes e campos, flags ligadas e o dono autenticado.
     *
     * @param  list<array<string, mixed>>  $recipients  mesmo formato de domainEnvelope()
     * @return array<string, mixed>
     */
    function flowDraft(array $recipients, SigningOrder $order = SigningOrder::Sequential, bool $steps = true, bool $delegation = true): array
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        flowEnableFlags($organization, $steps, $delegation);
        setPlanQuota($organization, 50);
        actingAsMember($owner, $organization);

        return domainEnvelope(['Contrato'], $recipients, $order, $organization, $owner, sent: false);
    }
}

if (! function_exists('flowSaveSteps')) {
    /**
     * @param  array<string, mixed>  $ctx
     * @param  list<array<string, mixed>>  $steps
     */
    function flowSaveSteps(object $test, array $ctx, array $steps): TestResponse
    {
        return $test->putJson(route('envelopes.steps.update', ['envelope' => $ctx['envelope']->ulid]), [
            'enabled' => true,
            'steps' => $steps,
        ]);
    }
}

if (! function_exists('flowSend')) {
    /**
     * @param  array<string, mixed>  $ctx
     */
    function flowSend(object $test, array $ctx): TestResponse
    {
        return $test->post(route('envelopes.send', ['envelope' => $ctx['envelope']->ulid]));
    }
}

if (! function_exists('flowSign')) {
    /**
     * O participante confirma o código, abre o documento e registra o aceite (ou a aprovação).
     *
     * @param  array<string, mixed>  $fields
     */
    function flowSign(object $test, string $token, array $fields = [], bool $withSignature = true): TestResponse
    {
        $props = domainAuthenticate($test, $token);
        domainPresent($test, $props);

        return domainAccept($test, $token, $props, $fields, $withSignature);
    }
}

if (! function_exists('flowRefuse')) {
    function flowRefuse(object $test, string $token, string $reason = 'Não concordo com as condições propostas.'): TestResponse
    {
        domainAuthenticate($test, $token);

        return $test->post(route('sign.refuse', ['token' => $token]), ['reason' => $reason]);
    }
}

if (! function_exists('flowDecisionRule')) {
    /**
     * @return array{match: string, rules: list<array<string, string>>}
     */
    function flowDecisionRule(string $approverUlid, string $equals, string $match = 'all'): array
    {
        return ['match' => $match, 'rules' => [['type' => 'approver_decision', 'recipient' => $approverUlid, 'equals' => $equals]]];
    }
}

if (! function_exists('flowApprovalScenario')) {
    /**
     * Aprovação (Paula) → se aprovou, assina Ana; se recusou, assina Bruno. Salvo e ENVIADO.
     *
     * @return array<string, mixed>
     */
    function flowApprovalScenario(object $test, SigningOrder $order = SigningOrder::Sequential): array
    {
        $ctx = flowDraft([
            ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
            ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
            ['name' => 'Bruno Jurídico', 'email' => 'bruno@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ], $order);

        $recipients = $ctx['recipients'];
        $paula = $recipients['paula@exemplo.test']->ulid;

        flowSaveSteps($test, $ctx, [
            ['name' => 'Aprovação', 'recipients' => [$paula]],
            ['name' => 'Assinatura da compradora', 'recipients' => [$recipients['ana@exemplo.test']->ulid], 'condition' => flowDecisionRule($paula, 'approved')],
            ['name' => 'Revisão jurídica', 'recipients' => [$recipients['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($paula, 'refused')],
        ])->assertOk();

        flowSend($test, $ctx)->assertSessionHasNoErrors();

        return $ctx;
    }
}

if (! function_exists('flowDelegationEnvelope')) {
    /**
     * Maria e João (signatários), delegação permitida pela política informada. Salvo e ENVIADO.
     *
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    function flowDelegationEnvelope(object $test, array $policy = [], SigningOrder $order = SigningOrder::Sequential): array
    {
        $ctx = flowDraft([
            ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
            ['name' => 'João Lima', 'email' => 'joao@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ], $order);

        $test->putJson(route('envelopes.delegation.update', ['envelope' => $ctx['envelope']->ulid]), array_merge([
            'allow' => true,
            'requires_confirmation' => true,
            'personal' => [],
        ], $policy))->assertOk();

        flowSend($test, $ctx)->assertSessionHasNoErrors();

        return $ctx;
    }
}

if (! function_exists('flowDelegate')) {
    /**
     * @param  array<string, string>  $data
     */
    function flowDelegate(object $test, string $token, array $data = []): TestResponse
    {
        return $test->postJson(route('sign.delegation.store', ['token' => $token]), array_merge([
            'name' => 'Carla Souza',
            'email' => 'carla@exemplo.test',
            'reason' => 'Estarei em viagem e a Carla tem procuração para responder por mim.',
        ], $data));
    }
}
