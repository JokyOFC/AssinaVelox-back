<?php

use App\Enums\EnvelopeStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\Envelopes\ApplyParticipantSignatureDeadline;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\LegalHold;
use App\Models\RetentionEvent;
use App\Services\Identity\CapturePurge;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use App\Services\Timestamp\Models\TimestampToken;
use App\Services\Timestamp\TsaKind;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/Retention/Support/RetentionHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2, onda C — integração I-2C (docs/fase-2/onda-c-relatorio.md §2)
|--------------------------------------------------------------------------
| O que a integração ligou entre as áreas K-A1, K-TSA e K-RET e nos arquivos de outros:
| flags compartilhadas, carimbos nas páginas, preservação no detalhe, nas pastas, no mover
| e na retenção das fotos, aviso na exclusão da organização e as rotinas agendadas.
*/

beforeEach(function () {
    Storage::fake('documents');
    config()->set('inertia.ssr.enabled', false);
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
});

function ondaCToken(Envelope $envelope, string $purpose): TimestampToken
{
    return TimestampToken::query()->create([
        'organization_id' => $envelope->organization_id,
        'envelope_id' => $envelope->getKey(),
        'purpose' => $purpose,
        'tsa_kind' => TsaKind::Operator,
        'provider' => 'operator',
        'environment' => 'test',
        'status' => 'granted',
        'hash_algorithm' => 'sha256',
        'imprint' => str_repeat('a', 64),
        'serial' => (string) random_int(1, 1_000_000),
        'gen_time' => now()->subMinute(),
        'policy_oid' => '2.25.1',
        'tsa_subject' => 'CN=TSA TESTE',
        'tsa_cert_fingerprint' => str_repeat('f', 64),
        'token_sha256' => str_repeat('b', 64),
        'verification' => ['valid' => true],
    ]);
}

it('compartilha as flags da onda C — desligadas por padrão, ligadas pela chave global E pelo plano', function () {
    expect(HandleInertiaRequests::features($this->organization))
        ->toMatchArray(['dossier_export' => false, 'retention_policies' => false, 'operator_tsa' => false, 'pades_bt' => false])
        ->not->toHaveKey('participant_a1');

    // Só a chave global: as flags de organização continuam desligadas (falta o plano).
    config()->set('assinavelox.features.dossier_export', true);
    config()->set('assinavelox.features.retention_policies', true);
    config()->set('assinavelox.features.operator_tsa', true);

    expect(HandleInertiaRequests::features($this->organization))
        ->toMatchArray(['dossier_export' => false, 'retention_policies' => false, 'operator_tsa' => true, 'pades_bt' => false]);

    retentionEnable($this->organization);
    $plan = $this->organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => [...(array) $plan->features, 'dossier_export' => true]])->save();

    expect(HandleInertiaRequests::features($this->organization->fresh()))
        ->toMatchArray(['dossier_export' => true, 'retention_policies' => true]);
});

it('carimbos: a página de evidências mostra todos; a verificação pública nunca o do manifesto do dossiê', function () {
    $ctx = retentionFinishedEnvelope($this->organization, $this->owner, 1);
    $envelope = $ctx['envelope'];
    actingAsMember($this->owner, $this->organization);

    // Sem carimbo nenhum (o caso de `operator_tsa` desligada), as chaves nem aparecem.
    $evidence = $this->get(route('envelopes.evidence', $envelope))->assertOk()->viewData('page')['props'];
    $public = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($evidence)->not->toHaveKey('timestamps')
        ->and($public)->not->toHaveKey('timestamps');

    ondaCToken($envelope, TimestampToken::PURPOSE_DOSSIER_MANIFEST);

    $evidence = $this->get(route('envelopes.evidence', $envelope))->assertOk()->viewData('page')['props'];
    $public = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($evidence['timestamps']['items'])->toHaveCount(1)
        ->and($evidence['timestamps']['items'][0]['label'])->toBe('Carimbo do tempo da operadora — não é carimbo ICP-Brasil')
        ->and($evidence['timestamps']['items'][0]['is_test'])->toBeTrue()
        // Publicar a hora do manifesto diria a quem tem o código quando o dossiê foi baixado.
        ->and($public)->not->toHaveKey('timestamps');

    ondaCToken($envelope, TimestampToken::PURPOSE_SIGNATURE);

    $public = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];

    expect($public['timestamps'])->toHaveCount(1)
        ->and(array_keys($public['timestamps'][0]))->toEqualCanonicalizing(['tsa_kind', 'label', 'purpose_label', 'gen_time', 'is_test'])
        ->and($public['timestamps'][0]['tsa_kind'])->toBe('operator')
        ->and($public['timestamps'][0]['label'])->toContain('não é carimbo ICP-Brasil')
        ->and(json_encode($public['timestamps']))->not->toContain(str_repeat('f', 64));
});

it('detalhe do documento recebe a preservação como prop (sem GET extra) e o selo reflete o bloqueio', function () {
    $ctx = retentionFinishedEnvelope($this->organization, $this->owner, 1);
    actingAsMember($this->owner, $this->organization);

    $props = $this->get(route('envelopes.show', $ctx['envelope']))->assertOk()->viewData('page')['props'];

    expect($props['legal_hold']['feature_enabled'])->toBeFalse()
        ->and($props['legal_hold']['preserved'])->toBeFalse()
        ->and($props)->not->toHaveKey('participant_signatures');

    retentionEnable($this->organization);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Ação judicial 0001234-56', envelope: $ctx['envelope']);

    $props = $this->get(route('envelopes.show', $ctx['envelope']))->assertOk()->viewData('page')['props'];

    expect($props['legal_hold']['preserved'])->toBeTrue()
        ->and($props['legal_hold']['holds'][0]['reason'])->toBe('Ação judicial 0001234-56');
});

it('mover para fora de uma pasta preservada é recusado; dentro da pasta, ou com bloqueio do próprio documento, não', function () {
    retentionEnable($this->organization);
    $parent = Folder::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $child = Folder::factory()->childOf($parent)->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $other = Folder::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $envelope = retentionFinishedEnvelope($this->organization, $this->owner, 1, attributes: ['folder_id' => $parent->id])['envelope'];

    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Folder, 'Auditoria interna', folder: $parent);
    actingAsMember($this->owner, $this->organization);

    $this->from(route('envelopes.show', $envelope))
        ->patch(route('envelopes.move', $envelope), ['folder_id' => $other->ulid])
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'preservado'));

    $this->from(route('envelopes.show', $envelope))
        ->patch(route('envelopes.move', $envelope), ['folder_id' => null])
        ->assertSessionHas('error');

    expect((int) $envelope->fresh()->folder_id)->toBe((int) $parent->id);

    // Para uma subpasta da pasta preservada: continua coberto, então pode.
    $this->patch(route('envelopes.move', $envelope), ['folder_id' => $child->ulid])->assertSessionHas('success');
    expect((int) $envelope->fresh()->folder_id)->toBe((int) $child->id);

    // Regra conservadora: mesmo com bloqueio do próprio documento, sair da pasta preservada é
    // recusado — quem preservou a pasta decide sobre o conteúdo dela.
    $hold = app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Pedido do jurídico', envelope: $envelope->fresh());
    $this->from(route('envelopes.show', $envelope))
        ->patch(route('envelopes.move', $envelope), ['folder_id' => $other->ulid])
        ->assertSessionHas('error');
    expect((int) $envelope->fresh()->folder_id)->toBe((int) $child->id);

    // Liberada a pasta, o bloqueio do documento não depende de pasta: pode mover.
    $folderHold = LegalHold::withoutOrganizationScope()->where('scope', 'folder')->firstOrFail();
    app(LegalHolds::class)->release($folderHold, $this->owner, 'Auditoria concluída');
    $this->patch(route('envelopes.move', $envelope), ['folder_id' => $other->ulid])->assertSessionHas('success');
    expect((int) $envelope->fresh()->folder_id)->toBe((int) $other->id)
        ->and($hold->fresh()->isActive())->toBeTrue();

    expect(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::HOLD_BLOCKED_DELETION)->where('payload->context', 'move')->count())->toBe(3);
});

it('mover em lote pula o documento protegido pela pasta e move os demais', function () {
    retentionEnable($this->organization);
    $held = Folder::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $target = Folder::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $protected = retentionFinishedEnvelope($this->organization, $this->owner, 1, attributes: ['folder_id' => $held->id])['envelope'];
    $free = retentionFinishedEnvelope($this->organization, $this->owner, 1)['envelope'];

    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Folder, 'Auditoria', folder: $held);
    actingAsMember($this->owner, $this->organization);

    $this->from(route('envelopes.index'))
        ->post(route('envelopes.bulk', ['action' => 'move']), ['ids' => [$protected->ulid, $free->ulid], 'folder_id' => $target->ulid])
        ->assertRedirect();

    expect((int) $protected->fresh()->folder_id)->toBe((int) $held->id)
        ->and((int) $free->fresh()->folder_id)->toBe((int) $target->id);
});

it('excluir pasta preservada (ou com conteúdo sob pasta acima preservada) é recusado; sem bloqueio, exclui', function () {
    retentionEnable($this->organization);
    $parent = Folder::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $child = Folder::factory()->childOf($parent)->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $emptyChild = Folder::factory()->childOf($parent)->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $loose = Folder::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    retentionFinishedEnvelope($this->organization, $this->owner, 1, attributes: ['folder_id' => $child->id]);

    $hold = app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Folder, 'Auditoria', folder: $parent);
    actingAsMember($this->owner, $this->organization);

    $this->from(route('envelopes.index'))->delete(route('folders.destroy', $parent))->assertSessionHas('error');
    $this->from(route('envelopes.index'))->delete(route('folders.destroy', $child))->assertSessionHas('error');

    expect(Folder::query()->whereKey([$parent->id, $child->id])->count())->toBe(2);

    // Subpasta vazia sob a pasta preservada e pasta sem bloqueio: nada a proteger.
    $this->delete(route('folders.destroy', $emptyChild))->assertSessionHas('success');
    $this->delete(route('folders.destroy', $loose))->assertSessionHas('success');

    app(LegalHolds::class)->release($hold, $this->owner, 'Auditoria concluída');
    $this->delete(route('folders.destroy', $child))->assertSessionHas('success');
});

it('a retenção global das fotos respeita a preservação', function () {
    $held = retentionFinishedEnvelope($this->organization, $this->owner, 400);
    $free = retentionFinishedEnvelope($this->organization, $this->owner, 400);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Perícia', envelope: $held['envelope']);

    $result = app(CapturePurge::class)->run();

    expect($result['expired'])->toBe(1)
        ->and($held['capture']->fresh()->storage_path)->not->toBeNull()
        ->and($free['capture']->fresh()->storage_path)->toBeNull()
        ->and($free['capture']->fresh()->purged_at)->not->toBeNull();
});

it('pedir a exclusão da organização avisa quando há preservação ativa', function () {
    actingAsMember($this->owner, $this->organization);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->from(route('settings.general'))->post(route('settings.organization.destroy'))
        ->assertSessionHas('warning', fn (string $message): bool => ! str_contains($message, 'preservação'));

    $this->delete(route('settings.organization.destroy'));

    $ctx = retentionFinishedEnvelope($this->organization, $this->owner, 1);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Ação trabalhista', envelope: $ctx['envelope']);

    $this->from(route('settings.general'))->post(route('settings.organization.destroy'))
        ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'preservação legal'));
});

it('participant-a1:maintain apaga material selado vencido e despacha o prazo dos envelopes vencidos', function () {
    Queue::fake();
    $sealed = storage_path('framework/testing/sealed-'.Str::lower(Str::random(8)));
    config()->set('assinavelox.participant_a1.sealed_path', $sealed);
    @mkdir($sealed, 0700, true);
    file_put_contents($sealed.'/velho.sealed', 'x');
    touch($sealed.'/velho.sealed', time() - 3600);
    file_put_contents($sealed.'/novo.sealed', 'x');

    $due = retentionFinishedEnvelope($this->organization, $this->owner, 1, EnvelopeStatus::Completed)['envelope'];
    DB::table('envelopes')->where('id', $due->id)->update(['status' => EnvelopeStatus::Finalizing->value]);
    $recipient = $due->recipients()->withoutGlobalScopes()->first();

    DB::table('participant_signature_requests')->insert([
        'ulid' => (string) Str::ulid(),
        'organization_id' => $this->organization->id,
        'envelope_id' => $due->id,
        'recipient_id' => $recipient->id,
        'status' => 'requested',
        'window_expires_at' => now()->subMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $this->artisan('participant-a1:maintain')->assertSuccessful();

        expect(file_exists($sealed.'/velho.sealed'))->toBeFalse()
            ->and(file_exists($sealed.'/novo.sealed'))->toBeTrue();

        Queue::assertPushed(ApplyParticipantSignatureDeadline::class, fn ($job): bool => $job->envelopeId === (int) $due->id);
    } finally {
        @unlink($sealed.'/novo.sealed');
        @rmdir($sealed);
    }
});

it('as rotinas da onda C estão agendadas', function () {
    $events = collect(app(Schedule::class)->events());
    $described = $events->map(fn ($event): string => (string) $event->command.' '.(string) $event->description)->implode(' | ');

    expect($described)->toContain('retention:apply')
        ->and($described)->toContain('participant-a1:maintain')
        ->and($described)->toContain('PurgeExpiredDossierExports');
});
