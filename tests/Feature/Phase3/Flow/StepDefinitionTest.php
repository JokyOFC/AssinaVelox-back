<?php

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningStep;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/Support/FlowHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 3 §3.3 (F-FLOW) — definição das etapas (wizard) e revalidação no envio
|--------------------------------------------------------------------------
| Esquema FECHADO: nada de expressão, chave desconhecida, operador fora da lista ou
| referência para a mesma etapa / para frente. A definição gravada é revalidada sob o lock
| do envio e copiada, com as referências remapeadas, ao duplicar.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    $this->invites = new ArrayObject;
    $this->inviteCounts = new ArrayObject;
    flowCaptureInvites($this->invites, $this->inviteCounts);

    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/**
 * @return array<string, mixed>
 */
function flowThreeParticipants(SigningOrder $order = SigningOrder::Sequential): array
{
    return flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [
            ['doc' => 0, 'type' => FieldType::Signature],
            ['doc' => 0, 'type' => FieldType::Text, 'required' => false, 'label' => 'Observação'],
        ]],
        ['name' => 'Bruno Jurídico', 'email' => 'bruno@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
    ], $order);
}

it('grava as etapas, recalcula a vez pela etapa e devolve o estado do fluxo', function () {
    $ctx = flowThreeParticipants();
    $r = $ctx['recipients'];

    flowSaveSteps($this, $ctx, [
        ['name' => 'Aprovação e compradora', 'recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['name' => 'Jurídico', 'recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($r['paula@exemplo.test']->ulid, 'approved')],
    ])->assertOk()
        ->assertJsonPath('steps.enabled', true)
        ->assertJsonPath('steps.items.1.condition.rules.0.equals', 'approved')
        ->assertJsonPath('steps.can_edit', true);

    $recipients = Recipient::query()->where('envelope_id', $ctx['envelope']->id)->get()->keyBy('email');

    // Sequencial com etapas: uma vez por participante, na ordem (etapa, posição); o visualizador fica fora.
    expect($recipients['paula@exemplo.test']->order_index)->toBe(1)
        ->and($recipients['ana@exemplo.test']->order_index)->toBe(2)
        ->and($recipients['bruno@exemplo.test']->order_index)->toBe(3)
        ->and($recipients['bruno@exemplo.test']->getAttribute('signing_step_index'))->toBe(2)
        ->and($recipients['victor@exemplo.test']->order_index)->toBe(0)
        ->and($recipients['victor@exemplo.test']->getAttribute('signing_step_index'))->toBeNull()
        ->and(AuditEvent::query()->where('event_type', 'signing_steps.updated')->count())->toBe(1);

    $this->getJson(route('envelopes.flow.show', ['envelope' => $ctx['envelope']->ulid]))
        ->assertOk()
        ->assertJsonPath('steps.items.0.status', 'pending')
        ->assertJsonPath('steps.fields.0.type', 'text')
        ->assertJsonPath('delegation.policy.allow', false)
        ->assertJsonPath('delegation.policy.requires_confirmation', true);

    // Desligar as etapas devolve a vez padrão do `signing_order`.
    $this->putJson(route('envelopes.steps.update', ['envelope' => $ctx['envelope']->ulid]), ['enabled' => false])->assertOk();

    $envelope = $ctx['envelope']->fresh();

    expect($envelope->usesSigningSteps())->toBeFalse()
        ->and(SigningStep::query()->where('envelope_id', $envelope->id)->count())->toBe(0)
        ->and(Recipient::query()->where('envelope_id', $envelope->id)->where('email', 'bruno@exemplo.test')->value('order_index'))->toBe(3)
        ->and(Recipient::query()->where('envelope_id', $envelope->id)->whereNotNull('signing_step_index')->count())->toBe(0);
});

it('recusa condição fora do esquema fechado, sem gravar nada', function (Closure $build, string $errorKey) {
    $ctx = flowThreeParticipants();
    $r = $ctx['recipients'];
    $text = $ctx['fields']['ana@exemplo.test:0:text'];
    $signature = $ctx['fields']['ana@exemplo.test:0:signature'];

    $steps = $build($r, $text->ulid, $signature->ulid);

    flowSaveSteps($this, $ctx, $steps)->assertStatus(422)->assertJsonValidationErrors([$errorKey]);

    expect($ctx['envelope']->fresh()->usesSigningSteps())->toBeFalse()
        ->and(SigningStep::query()->count())->toBe(0);
})->with([
    'expressão em texto' => [fn (array $r) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => 'decisao(paula) == "aprovado"'],
    ], 'steps.1.condition'],
    'chave desconhecida' => [fn (array $r) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($r['paula@exemplo.test']->ulid, 'approved') + ['expression' => '1==1']],
    ], 'steps.1.condition'],
    'tipo de regra desconhecido' => [fn (array $r) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => ['match' => 'all', 'rules' => [['type' => 'script', 'code' => 'return true;']]]],
    ], 'steps.1.condition.rules.0.type'],
    'operador fora da lista' => [fn (array $r, string $text) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => ['match' => 'all', 'rules' => [['type' => 'field_value', 'field' => $text, 'operator' => 'regex', 'value' => '.*']]]],
    ], 'steps.1.condition.rules.0.operator'],
    'campo de assinatura' => [fn (array $r, string $text, string $signature) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => ['match' => 'all', 'rules' => [['type' => 'field_value', 'field' => $signature, 'operator' => 'equals', 'value' => 'x']]]],
    ], 'steps.1.condition.rules.0.field'],
    'valor com quebra de linha' => [fn (array $r, string $text) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => ['match' => 'all', 'rules' => [['type' => 'field_value', 'field' => $text, 'operator' => 'equals', 'value' => "sim\nnão"]]]],
    ], 'steps.1.condition.rules.0.value'],
    'decisão de quem não é aprovador' => [fn (array $r) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($r['ana@exemplo.test']->ulid, 'approved')],
    ], 'steps.1.condition.rules.0.recipient'],
    'referência para a mesma etapa' => [fn (array $r) => [
        ['recipients' => [$r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($r['paula@exemplo.test']->ulid, 'approved')],
    ], 'steps.1.condition.rules.0.recipient'],
    'referência para frente' => [fn (array $r) => [
        ['recipients' => [$r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($r['paula@exemplo.test']->ulid, 'approved')],
        ['recipients' => [$r['paula@exemplo.test']->ulid]],
    ], 'steps.1.condition.rules.0.recipient'],
    'campo de etapa posterior' => [fn (array $r, string $text) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => ['match' => 'all', 'rules' => [['type' => 'field_value', 'field' => $text, 'operator' => 'contains', 'value' => 'ok']]]],
        ['recipients' => [$r['ana@exemplo.test']->ulid]],
    ], 'steps.1.condition.rules.0.field'],
    'condição na primeira etapa' => [fn (array $r) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid, $r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($r['paula@exemplo.test']->ulid, 'approved')],
    ], 'steps.0.condition'],
    'visualizador em etapa' => [fn (array $r) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid, $r['bruno@exemplo.test']->ulid, $r['victor@exemplo.test']->ulid]],
    ], 'steps.0.recipients'],
    'participante sem etapa' => [fn (array $r) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
    ], 'steps'],
    'participante em duas etapas' => [fn (array $r) => [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['ana@exemplo.test']->ulid, $r['bruno@exemplo.test']->ulid]],
    ], 'steps.1.recipients'],
]);

it('revalida a definição no envio: referência que deixou de valer impede o envio', function () {
    $ctx = flowThreeParticipants();
    $r = $ctx['recipients'];

    flowSaveSteps($this, $ctx, [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => flowDecisionRule($r['paula@exemplo.test']->ulid, 'approved')],
    ])->assertOk();

    // Depois de salvar as etapas, a aprovadora virou signatária (com campo de assinatura).
    $r['paula@exemplo.test']->forceFill(['role' => RecipientRole::Signer])->save();
    Recipient::query()->whereKey($r['paula@exemplo.test']->id)->first()?->fields()->create([
        'envelope_id' => $ctx['envelope']->id,
        'organization_id' => $ctx['organization']->id,
        'document_version_id' => $ctx['versions'][0]->id,
        'type' => FieldType::Signature,
        'page' => 1, 'x' => 0.5, 'y' => 0.5, 'width' => 0.2, 'height' => 0.05,
    ]);

    flowSend($this, $ctx);

    expect($ctx['envelope']->fresh()->status)->not->toBe(EnvelopeStatus::InProgress)
        ->and($this->invites->getArrayCopy())->toBe([]);
});

it('etapas não mudam depois do envio', function () {
    $ctx = flowThreeParticipants();
    $r = $ctx['recipients'];
    $steps = [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid]],
    ];

    flowSaveSteps($this, $ctx, $steps)->assertOk();
    flowSend($this, $ctx)->assertSessionHasNoErrors();

    flowSaveSteps($this, $ctx, array_reverse($steps))->assertStatus(422)->assertJsonValidationErrors(['steps']);
});

it('duplicar copia as etapas com as referências da cópia, todas aguardando', function () {
    $ctx = flowThreeParticipants();
    $r = $ctx['recipients'];
    $text = $ctx['fields']['ana@exemplo.test:0:text'];

    flowSaveSteps($this, $ctx, [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['ana@exemplo.test']->ulid]],
        ['recipients' => [$r['bruno@exemplo.test']->ulid], 'condition' => ['match' => 'any', 'rules' => [
            ['type' => 'approver_decision', 'recipient' => $r['paula@exemplo.test']->ulid, 'equals' => 'refused'],
            ['type' => 'field_value', 'field' => $text->ulid, 'operator' => 'contains', 'value' => 'urgente'],
        ]]],
    ])->assertOk();

    $this->post(route('envelopes.duplicate', ['envelope' => $ctx['envelope']->ulid]))->assertRedirect();

    $copy = Envelope::query()->where('id', '!=', $ctx['envelope']->id)->latest('id')->firstOrFail();
    $copyPaula = Recipient::query()->where('envelope_id', $copy->id)->where('email', 'paula@exemplo.test')->sole();
    $copyText = $copy->fields()->where('type', FieldType::Text->value)->sole();
    $step = SigningStep::query()->where('envelope_id', $copy->id)->where('step_index', 2)->sole();

    expect($copy->usesSigningSteps())->toBeTrue()
        ->and($step->status)->toBe('pending')
        ->and($step->condition['rules'][0]['recipient'])->toBe($copyPaula->ulid)
        ->and($step->condition['rules'][1]['field'])->toBe($copyText->ulid)
        ->and(Recipient::query()->where('envelope_id', $copy->id)->where('email', 'bruno@exemplo.test')->value('signing_step_index'))->toBe(2);
});
