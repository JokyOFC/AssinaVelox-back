<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\PlanConsumption;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Envelopes\Sending\ScheduledSend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/ReminderHelpers.php';

/*
| Envio agendado (Fase 2 §2.5): fuso da organização, disparo na hora, revalidação de cota e
| completude no disparo, uma única vez em corrida, edição cancela, cancelamento, flag e
| isolamento. Agora: segunda 14/09, 10:00 em São Paulo (13:00 UTC).
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
    remTravel('2026-09-14 13:00:00');
    remRegisterRoutes();
});

afterEach(fn () => Carbon::setTestNow());

const REM_DUE = '2026-09-15 12:00:00'; // 15/09 09:00 em São Paulo

test('agenda no fuso da organização e envia na hora marcada', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    actingAsMember($owner, $organization);

    $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => '2026-09-15T09:00'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $envelope->refresh();
    expect(ScheduledSend::scheduledAt($envelope)?->toIso8601String())->toBe('2026-09-15T12:00:00+00:00')
        ->and($envelope->status)->toBe(EnvelopeStatus::Ready)
        ->and(remAuditCount($envelope, 'envelope.scheduled'))->toBe(1)
        ->and(PlanConsumption::withoutOrganizationScope()->where('envelope_id', $envelope->id)->count())->toBe(0);

    expect(ScheduledSend::present($envelope))->toMatchArray([
        'at_local' => '15/09/2026 às 09:00',
        'input_value' => '2026-09-15T09:00',
        'timezone' => 'America/Sao_Paulo',
    ]);

    remTravel('2026-09-15 11:59:00');
    remRunScheduled();
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and(remProvider()->to('maria@exemplo.com'))->toBe([]);

    remTravel(REM_DUE);
    remRunScheduled();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and(ScheduledSend::scheduledAt($envelope))->toBeNull()
        ->and(remProvider()->to('maria@exemplo.com'))->toHaveCount(1)
        ->and(remLastAudit($envelope, 'envelope.sent')?->payload['scheduled_for'])->toBe('2026-09-15T12:00:00+00:00');
});

test('no disparo a cota é revalidada: sem cota não envia, cancela e avisa o remetente', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    setPlanQuota($organization, 0);

    remTravel(REM_DUE);
    remRunScheduled();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Ready)
        ->and(ScheduledSend::scheduledAt($envelope))->toBeNull()
        ->and(remProvider()->to('maria@exemplo.com'))->toBe([])
        ->and(remLastAudit($envelope, 'envelope.schedule_canceled')?->payload['reason'])->toBe('quota_exhausted')
        ->and($owner->fresh()->notifications()->where('data->event', 'scheduled_send_failed')->count())->toBe(1)
        ->and(PlanConsumption::withoutOrganizationScope()->where('envelope_id', $envelope->id)->count())->toBe(0);
});

test('no disparo a completude é revalidada', function () {
    [$envelope, , $owner] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    // Alteração que não passa pela trilha (a trilha já cobre as edições pela interface).
    DB::table('signing_fields')->where('envelope_id', $envelope->id)->delete();

    remTravel(REM_DUE);
    remRunScheduled();

    $envelope->refresh();
    expect($envelope->status)->not->toBe(EnvelopeStatus::InProgress)
        ->and(remProvider()->to('maria@exemplo.com'))->toBe([])
        ->and(remLastAudit($envelope, 'envelope.schedule_canceled')?->payload['reason'])->toBe('incomplete')
        ->and($owner->fresh()->notifications()->where('data->event', 'scheduled_send_failed')->count())->toBe(1);
});

test('agendar exige antecedência mínima, formato válido, documento pronto e cota', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    actingAsMember($owner, $organization);

    $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => '2026-09-14T10:02'])
        ->assertSessionHasErrors('scheduled_for');
    $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => 'amanhã'])
        ->assertSessionHasErrors('scheduled_for');
    $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => '2027-01-30T09:00'])
        ->assertSessionHasErrors('scheduled_for');

    setPlanQuota($organization, 0);
    $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => '2026-09-15T09:00'])
        ->assertSessionHas('error');

    setPlanQuota($organization, null);
    DB::table('signing_fields')->where('envelope_id', $envelope->id)->delete();
    $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => '2026-09-15T09:00'])
        ->assertSessionHas('error');

    expect(ScheduledSend::scheduledAt($envelope->fresh()))->toBeNull()
        ->and(remAuditCount($envelope, 'envelope.scheduled'))->toBe(0);
});

test('o disparo acontece uma única vez, mesmo com dois processos', function () {
    [$envelope] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    remTravel(REM_DUE);

    $first = app(ScheduledSend::class)->fire((int) $envelope->id, REM_DUE);
    $second = app(ScheduledSend::class)->fire((int) $envelope->id, REM_DUE);

    expect([$first, $second])->toBe(['sent', 'noop']);

    remRunScheduled();

    expect(remAuditCount($envelope, 'envelope.sent'))->toBe(1)
        ->and(PlanConsumption::withoutOrganizationScope()->where('envelope_id', $envelope->id)->count())->toBe(1)
        ->and(remProvider()->to('maria@exemplo.com'))->toHaveCount(1);
});

test('"Enviar agora" encerra o agendamento e o disparo posterior não faz nada', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    actingAsMember($owner, $organization);
    $this->post(route('envelopes.send', $envelope))->assertRedirect();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and(ScheduledSend::scheduledAt($envelope))->toBeNull()
        ->and(remLastAudit($envelope, 'envelope.schedule_canceled')?->payload['reason'])->toBe('sent_now');

    remTravel(REM_DUE);
    expect(app(ScheduledSend::class)->fire((int) $envelope->id, REM_DUE))->toBe('noop');
    expect(remAuditCount($envelope, 'envelope.sent'))->toBe(1);
});

test('editar o envelope depois de agendado cancela o agendamento', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    actingAsMember($owner, $organization);
    $this->patch(route('envelopes.update', $envelope), ['title' => 'Contrato revisado'])->assertRedirect();

    remTravel('2026-09-14 13:01:00');
    remRunScheduled();

    $envelope->refresh();
    expect(ScheduledSend::scheduledAt($envelope))->toBeNull()
        ->and(remLastAudit($envelope, 'envelope.schedule_canceled')?->payload['reason'])->toBe('edited');

    remTravel(REM_DUE);
    remRunScheduled();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and(remProvider()->count())->toBe(0);
});

test('uma edição registrada depois do agendamento é detectada também no disparo', function () {
    [$envelope] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    EnvelopeAudit::record($envelope->fresh(), AuditEventType::FieldsUpdated, ['fields' => 1]);

    remTravel(REM_DUE);

    expect(app(ScheduledSend::class)->fire((int) $envelope->id, REM_DUE))->toBe('canceled:edited')
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and(remProvider()->count())->toBe(0);
});

test('o ponto de extensão cancelBecauseEdited cancela na hora', function () {
    [$envelope] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    expect(app(ScheduledSend::class)->cancelBecauseEdited($envelope, 'recipients'))->toBeTrue();

    $event = remLastAudit($envelope, 'envelope.schedule_canceled');
    expect(ScheduledSend::scheduledAt($envelope->fresh()))->toBeNull()
        ->and($event?->payload['reason'])->toBe('edited')
        ->and($event?->payload['change'])->toBe('recipients');
});

test('o usuário cancela o agendamento e o documento continua pronto', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    actingAsMember($owner, $organization);
    $this->delete(route('envelopes.schedule.cancel', $envelope))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(ScheduledSend::scheduledAt($envelope->fresh()))->toBeNull()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and(remLastAudit($envelope, 'envelope.schedule_canceled')?->payload['reason'])->toBe('user');

    $this->delete(route('envelopes.schedule.cancel', $envelope))->assertSessionHas('info');

    remTravel(REM_DUE);
    remRunScheduled();
    expect(remProvider()->count())->toBe(0);
});

test('cancelar o envelope encerra o agendamento', function () {
    [$envelope] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    app(CancelEnvelope::class)->handle($envelope->fresh(), 'Não vai mais');

    expect(ScheduledSend::scheduledAt($envelope->fresh()))->toBeNull()
        ->and(remLastAudit($envelope, 'envelope.schedule_canceled')?->payload['reason'])->toBe('envelope_canceled');
});

test('com a flag desligada as rotas do agendamento e dos lembretes respondem 404', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule(enable: false);
    actingAsMember($owner, $organization);

    $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => '2026-09-15T09:00'])->assertNotFound();
    $this->delete(route('envelopes.schedule.cancel', $envelope))->assertNotFound();
    $this->put(route('envelopes.reminders.update', $envelope), [
        'enabled' => true, 'first_after_days' => 2, 'interval_days' => 2, 'max_count' => 3,
    ])->assertNotFound();

    expect(ScheduledSend::scheduledAt($envelope->fresh()))->toBeNull();
});

test('se a flag é desligada depois de agendar, o disparo não envia e avisa o remetente', function () {
    [$envelope, , $owner] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse(REM_DUE));

    config(['assinavelox.features.reminders' => false]);

    remTravel(REM_DUE);
    remRunScheduled();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and(remProvider()->to('maria@exemplo.com'))->toBe([])
        ->and(remLastAudit($envelope, 'envelope.schedule_canceled')?->payload['reason'])->toBe('feature_disabled')
        ->and($owner->fresh()->notifications()->where('data->event', 'scheduled_send_failed')->count())->toBe(1);
});

test('outra organização não agenda envelope alheio', function () {
    [$envelope] = remReadyForSchedule();
    ['organization' => $other, 'owner' => $intruder] = remOrganization();

    actingAsMember($intruder, $other);
    $response = $this->post(route('envelopes.schedule', $envelope), ['scheduled_for' => '2026-09-15T09:00']);

    expect($response->status())->toBeIn([403, 404])
        ->and(ScheduledSend::scheduledAt($envelope->fresh()))->toBeNull();
});
