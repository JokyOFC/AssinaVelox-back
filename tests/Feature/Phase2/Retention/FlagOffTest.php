<?php

use App\Models\Envelope;
use App\Models\LegalHold;
use App\Models\RetentionDeletion;
use App\Services\Retention\RetentionRunner;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/RetentionHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.19 (K-RET) — flag `retention_policies` desligada = comportamento atual
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake('documents');
    config()->set('inertia.ssr.enabled', false);
    $this->withoutVite();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
});

it('o job não seleciona nada, mesmo com uma política ativa gravada', function () {
    $old = retentionFinishedEnvelope($this->organization, $this->owner, 4000);
    retentionPolicyFor($this->organization, ['completed' => 1825, 'identity_capture' => 7]);

    $summary = app(RetentionRunner::class)->run();

    expect($summary['organizations'])->toBe(0)
        ->and(Envelope::withoutOrganizationScope()->whereKey($old['envelope']->id)->exists())->toBeTrue()
        ->and($old['capture']->fresh()->storage_path)->not->toBeNull()
        ->and(RetentionDeletion::withoutOrganizationScope()->count())->toBe(0);
});

it('a tela mostra o estado "Fase 2"; gravar e preservar respondem 404', function () {
    $envelope = retentionFinishedEnvelope($this->organization, $this->owner, 10)['envelope'];
    actingAsMember($this->owner, $this->organization);

    $props = $this->get(route('settings.retention'))->assertOk()->viewData('page')['props'];

    expect($props['enabled'])->toBeFalse();

    $this->put(route('settings.retention.update'), ['is_active' => true, 'periods' => ['completed' => 3650], 'confirmation' => 'REDUZIR PRAZOS'])->assertNotFound();
    $this->post(route('envelopes.legal_hold.store', ['envelope' => $envelope->ulid]), ['reason' => 'Teste com a flag desligada'])->assertNotFound();
    $this->post(route('settings.retention.holds.store'), ['scope' => 'organization', 'reason' => 'Teste com a flag desligada'])->assertNotFound();

    expect(LegalHold::withoutOrganizationScope()->count())->toBe(0);
});

it('a exclusão manual de rascunho e a verificação pública seguem como antes', function () {
    $draft = readyEnvelope($this->organization, $this->owner);
    actingAsMember($this->owner, $this->organization);

    $this->delete(route('envelopes.destroy', ['envelope' => $draft->ulid]))
        ->assertRedirect(route('envelopes.index'))
        ->assertSessionHas('success', 'Rascunho excluído.');

    expect(Envelope::withoutOrganizationScope()->withTrashed()->whereKey($draft->id)->value('deleted_at'))->not->toBeNull();

    $props = verifyProps($this->get(route('verify.show', ['code' => 'ABCDEFGHJKLM']))->assertOk());

    expect($props['found'])->toBeFalse()->and($props['result'])->toBeNull();
});
