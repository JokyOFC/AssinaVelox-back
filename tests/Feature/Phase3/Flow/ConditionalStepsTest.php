<?php

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningStep;
use App\Services\Envelopes\Reminders\ReminderPlanner;
use App\Services\Envelopes\Reminders\ReminderSender;
use App\Services\Envelopes\Sending\InvitationDispatcher;
use App\Services\Envelopes\Sending\ResendInvitations;
use App\Services\Envelopes\Steps\ConditionEvaluator;
use App\Services\Envelopes\Steps\EvaluationFacts;
use App\Services\Envelopes\Steps\StepCondition;
use App\Services\Envelopes\Steps\StepProgression;
use App\Services\Signing\SignerLinkResolver;
use Illuminate\Support\Facades\DB;
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
| Fase 3 §3.3 (F-FLOW) — etapas condicionais
|--------------------------------------------------------------------------
| Os dois ramos de uma condição de aprovação, a recusa do aprovador, condição por campo no
| paralelo, concorrência (uma avaliação, um convite) e o respeito à etapa corrente em
| lembrete, reenvio e página pública.
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
 * @return list<string>
 */
function flowStepStatuses(Envelope $envelope): array
{
    return SigningStep::query()->where('envelope_id', $envelope->id)->orderBy('step_index')->pluck('status')->all();
}

it('segue o ramo "aprovou": a etapa condicionada à aprovação corre e a da recusa é pulada sem aviso', function () {
    $ctx = flowApprovalScenario($this);
    $envelope = $ctx['envelope']->fresh();
    $recipients = $ctx['recipients'];

    // No envio só a etapa 1 é convidada; a vez de cada um vem da etapa.
    expect($envelope->usesSigningSteps())->toBeTrue()
        ->and(array_keys($this->invites->getArrayCopy()))->toBe(['paula@exemplo.test'])
        ->and(flowStepStatuses($envelope))->toBe(['active', 'pending', 'pending']);

    flowSign($this, $this->invites['paula@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();

    expect(flowStepStatuses($envelope))->toBe(['active', 'active', 'pending'])
        ->and(isset($this->invites['ana@exemplo.test']))->toBeTrue()
        ->and(isset($this->invites['bruno@exemplo.test']))->toBeFalse();

    $started = AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', 'envelope.step_started')->sole();

    expect($started->payload['step_index'])->toBe(2)
        ->and($started->payload['result'])->toBeTrue()
        ->and($started->payload['rules'][0]['observed'])->toBe('approved');

    flowSign($this, $this->invites['ana@exemplo.test'])->assertSessionHasNoErrors();

    $bruno = $recipients['bruno@exemplo.test']->fresh();
    $skipped = AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', 'envelope.step_skipped')->sole();

    expect(flowStepStatuses($envelope))->toBe(['active', 'active', 'skipped'])
        ->and($bruno->status)->toBe(RecipientStatus::Canceled)
        ->and($bruno->getAttribute('status_reason'))->toBe('step_skipped')
        ->and($bruno->notification_count)->toBe(0)
        ->and(isset($this->invites['bruno@exemplo.test']))->toBeFalse()
        ->and($skipped->payload['result'])->toBeFalse()
        ->and($skipped->payload['rules'][0]['expected'])->toBe('refused')
        ->and($skipped->payload['rules'][0]['observed'])->toBe('approved')
        ->and($skipped->payload['canceled_recipients'])->toBe([$bruno->ulid])
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Finalizing);

    // A regra e os valores avaliados ficam gravados na etapa (evidência, não cache).
    $step = SigningStep::query()->where('envelope_id', $envelope->id)->where('step_index', 3)->sole();

    expect($step->evaluation['rules'][0]['decided_by'])->toBe('Paula Aprovadora')
        ->and($step->evaluated_at)->not->toBeNull();

    Event::assertDispatched(EnvelopeReadyForFinalization::class);
});

it('segue o ramo "recusou": a recusa do aprovador não encerra, pula a etapa da aprovação e libera a da recusa', function () {
    $ctx = flowApprovalScenario($this);
    $envelope = $ctx['envelope'];
    $recipients = $ctx['recipients'];

    flowRefuse($this, $this->invites['paula@exemplo.test'])->assertSessionHasNoErrors();

    $envelope->refresh();
    $ana = $recipients['ana@exemplo.test']->fresh();

    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and($recipients['paula@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Refused)
        ->and($ana->status)->toBe(RecipientStatus::Canceled)
        ->and($ana->getAttribute('status_reason'))->toBe('step_skipped')
        ->and(isset($this->invites['ana@exemplo.test']))->toBeFalse()
        ->and($this->inviteCounts['bruno@exemplo.test'] ?? 0)->toBe(1)
        ->and($envelope->current_order)->toBe(3)
        ->and(flowStepStatuses($envelope))->toBe(['active', 'skipped', 'active'])
        ->and(AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', 'envelope.refused')->exists())->toBeFalse();

    flowSign($this, $this->invites['bruno@exemplo.test'])->assertSessionHasNoErrors();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Finalizing)
        ->and($recipients['bruno@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Signed);

    Event::assertDispatched(EnvelopeReadyForFinalization::class);
});

it('recusa de aprovador cuja decisão nenhuma etapa lê encerra o documento como sempre', function () {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ]);

    flowSaveSteps($this, $ctx, [
        ['recipients' => [$ctx['recipients']['paula@exemplo.test']->ulid]],
        ['recipients' => [$ctx['recipients']['ana@exemplo.test']->ulid]],
    ])->assertOk();

    flowSend($this, $ctx)->assertSessionHasNoErrors();
    flowRefuse($this, $this->invites['paula@exemplo.test'])->assertSessionHasNoErrors();

    $ana = $ctx['recipients']['ana@exemplo.test']->fresh();

    expect($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Refused)
        ->and($ana->status)->toBe(RecipientStatus::Canceled)
        ->and($ana->getAttribute('status_reason'))->toBeNull()
        ->and(isset($this->invites['ana@exemplo.test']))->toBeFalse();
});

it('no paralelo com etapas a etapa inteira assina junta e a condição de campo decide a próxima', function (bool $checked) {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Carla Locadora', 'email' => 'carla@exemplo.test', 'fields' => [
            ['doc' => 0, 'type' => FieldType::Signature],
            ['doc' => 0, 'type' => FieldType::Checkbox, 'required' => false, 'label' => 'Exige fiador'],
        ]],
        ['name' => 'Ana Fiadora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Bruno Fiador', 'email' => 'bruno@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Parallel);

    $r = $ctx['recipients'];
    $checkbox = $ctx['fields']['carla@exemplo.test:0:checkbox'];

    flowSaveSteps($this, $ctx, [
        ['name' => 'Aprovação e locadora', 'recipients' => [$r['paula@exemplo.test']->ulid, $r['carla@exemplo.test']->ulid]],
        ['name' => 'Fiadores', 'recipients' => [$r['ana@exemplo.test']->ulid, $r['bruno@exemplo.test']->ulid], 'condition' => [
            'match' => 'all',
            'rules' => [
                ['type' => 'field_value', 'field' => $checkbox->ulid, 'operator' => 'equals', 'value' => 'checked'],
                ['type' => 'approver_decision', 'recipient' => $r['paula@exemplo.test']->ulid, 'equals' => 'approved'],
            ],
        ]],
    ])->assertOk();

    // No paralelo com etapas, a vez é a etapa: 1 para a primeira, 2 para os fiadores.
    expect($r['ana@exemplo.test']->fresh()->order_index)->toBe(2)
        ->and($r['carla@exemplo.test']->fresh()->order_index)->toBe(1);

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    expect(array_keys($this->invites->getArrayCopy()))->toEqualCanonicalizing(['paula@exemplo.test', 'carla@exemplo.test']);

    flowSign($this, $this->invites['carla@exemplo.test'], [$checkbox->ulid => $checked ? '1' : '0'])->assertSessionHasNoErrors();

    // Falta a aprovadora: a etapa 2 ainda não foi avaliada.
    expect(flowStepStatuses($ctx['envelope']))->toBe(['active', 'pending'])
        ->and(isset($this->invites['ana@exemplo.test']))->toBeFalse();

    flowSign($this, $this->invites['paula@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();

    $envelope = $ctx['envelope']->fresh();

    if ($checked) {
        expect(flowStepStatuses($envelope))->toBe(['active', 'active'])
            ->and($envelope->current_order)->toBe(2)
            ->and(array_keys($this->invites->getArrayCopy()))->toEqualCanonicalizing(['paula@exemplo.test', 'carla@exemplo.test', 'ana@exemplo.test', 'bruno@exemplo.test'])
            ->and($envelope->status)->toBe(EnvelopeStatus::InProgress);

        flowSign($this, $this->invites['ana@exemplo.test'])->assertSessionHasNoErrors();
        expect($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::InProgress);

        flowSign($this, $this->invites['bruno@exemplo.test'])->assertSessionHasNoErrors();
    } else {
        expect(flowStepStatuses($envelope))->toBe(['active', 'skipped'])
            ->and($r['ana@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Canceled)
            ->and($r['bruno@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Canceled)
            ->and(isset($this->invites['ana@exemplo.test']))->toBeFalse();
    }

    expect($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Finalizing);
})->with(['caixa marcada' => [true], 'caixa desmarcada' => [false]]);

it('duas decisões na mesma etapa avaliam a próxima uma única vez e convidam uma única vez', function () {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Quintino Aprovador', 'email' => 'quintino@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Parallel);

    $r = $ctx['recipients'];

    flowSaveSteps($this, $ctx, [
        ['recipients' => [$r['paula@exemplo.test']->ulid, $r['quintino@exemplo.test']->ulid]],
        ['recipients' => [$r['ana@exemplo.test']->ulid], 'condition' => flowDecisionRule($r['quintino@exemplo.test']->ulid, 'approved')],
    ])->assertOk();

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    flowSign($this, $this->invites['paula@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();
    flowSign($this, $this->invites['quintino@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();

    // Execuções atrasadas do mesmo recálculo (outro processo que também viu as duas decisões).
    $envelope = $ctx['envelope'];

    foreach (range(1, 2) as $attempt) {
        DB::transaction(function () use ($envelope): void {
            $locked = Envelope::withoutOrganizationScope()->whereKey($envelope->id)->lockForUpdate()->firstOrFail();
            $recipients = Recipient::withoutOrganizationScope()->where('envelope_id', $locked->id)->orderBy('order_index')->orderBy('id')->get();

            app(StepProgression::class)->prepare($locked, $recipients, (string) Str::ulid());
        });
    }

    expect(AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', 'envelope.step_started')->count())->toBe(1)
        ->and($this->inviteCounts['ana@exemplo.test'])->toBe(1)
        ->and($r['ana@exemplo.test']->fresh()->notification_count)->toBe(1);
});

it('uma etapa de condição falsa é pulada uma única vez mesmo com recálculos concorrentes a partir do mesmo estado', function () {
    $ctx = flowApprovalScenario($this);
    $envelope = $ctx['envelope'];
    $r = $ctx['recipients'];

    flowSign($this, $this->invites['paula@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();

    // A compradora concluiu; dois processos leram o MESMO estado antes de qualquer um gravar.
    $r['ana@exemplo.test']->fresh()->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now()])->save();

    $stale = [
        Recipient::withoutOrganizationScope()->where('envelope_id', $envelope->id)->orderBy('order_index')->orderBy('id')->get(),
        Recipient::withoutOrganizationScope()->where('envelope_id', $envelope->id)->orderBy('order_index')->orderBy('id')->get(),
    ];

    foreach ($stale as $recipients) {
        DB::transaction(function () use ($envelope, $recipients): void {
            $locked = Envelope::withoutOrganizationScope()->whereKey($envelope->id)->lockForUpdate()->firstOrFail();
            app(StepProgression::class)->prepare($locked, $recipients, (string) Str::ulid());
        });
    }

    expect(AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', 'envelope.step_skipped')->count())->toBe(1)
        ->and($r['bruno@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Canceled)
        ->and(isset($this->invites['bruno@exemplo.test']))->toBeFalse();
});

it('lembrete, reenvio, convite e página pública respeitam a etapa corrente também no paralelo', function () {
    $ctx = flowDraft([
        ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test', 'role' => RecipientRole::Approver],
        ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Parallel);

    flowSaveSteps($this, $ctx, [
        ['recipients' => [$ctx['recipients']['paula@exemplo.test']->ulid]],
        ['recipients' => [$ctx['recipients']['ana@exemplo.test']->ulid]],
    ])->assertOk();

    flowSend($this, $ctx)->assertSessionHasNoErrors();

    $envelope = $ctx['envelope']->fresh();
    $ana = $ctx['recipients']['ana@exemplo.test']->fresh();

    expect($envelope->hasTurns())->toBeTrue()
        ->and(SignerLinkResolver::stateFor($envelope, $ana))->toBeNull()
        ->and(app(InvitationDispatcher::class)->isTheirTurn($ana, $envelope))->toBeFalse()
        ->and(app(ResendInvitations::class)->eligible($envelope)->pluck('email')->all())->toBe(['paula@exemplo.test'])
        ->and(isset($this->invites['ana@exemplo.test']))->toBeFalse();

    // Mesmo que alguém esteja marcado como convidado, fora da etapa corrente não há lembrete.
    $ana->forceFill(['status' => RecipientStatus::Notified, 'last_notified_at' => now()->subDays(10)])->save();

    expect(app(ReminderPlanner::class)->eligibleRecipients($envelope)->pluck('email')->all())->toBe(['paula@exemplo.test'])
        ->and(app(ReminderSender::class)->stopReason($envelope, $ana->fresh(), now()))->toBe('not_their_turn');
});

it('o motor compara texto sem avaliar nada: igual, diferente e contém são literais', function () {
    $condition = StepCondition::fromArray([
        'match' => 'any',
        'rules' => [
            ['type' => 'field_value', 'field' => str_repeat('A', 26), 'operator' => 'contains', 'value' => '${7*7}'],
            ['type' => 'field_value', 'field' => str_repeat('B', 26), 'operator' => 'equals', 'value' => 'sim'],
        ],
    ]);

    $facts = new EvaluationFacts([], [
        str_repeat('A', 26) => ['type' => 'text', 'text' => 'valor 49', 'bool' => null, 'label' => 'Observação'],
        str_repeat('B', 26) => ['type' => 'text', 'text' => '  SIM ', 'bool' => null, 'label' => 'Aceita'],
    ]);

    $result = app(ConditionEvaluator::class)->evaluate($condition, $facts);

    expect($result['rules'][0]['result'])->toBeFalse()
        ->and($result['rules'][1]['result'])->toBeTrue()
        ->and($result['result'])->toBeTrue();

    $all = StepCondition::fromArray([
        'match' => 'all',
        'rules' => [
            ['type' => 'field_value', 'field' => str_repeat('B', 26), 'operator' => 'not_equals', 'value' => 'sim'],
            ['type' => 'approver_decision', 'recipient' => str_repeat('C', 26), 'equals' => 'approved'],
        ],
    ]);

    $none = app(ConditionEvaluator::class)->evaluate($all, $facts);

    expect($none['result'])->toBeFalse()
        ->and($none['rules'][1]['observed'])->toBe('none');
});
