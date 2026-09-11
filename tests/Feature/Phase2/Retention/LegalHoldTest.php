<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\LegalHold;
use App\Models\Organization;
use App\Models\RetentionEvent;
use App\Services\Organizations\OrganizationPurge;
use App\Services\Retention\Exceptions\LegalHoldActiveException;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use App\Services\Retention\RetentionRunner;
use App\Support\OrganizationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/RetentionHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.19 (K-RET) — bloqueio de exclusão por preservação
|--------------------------------------------------------------------------
| Nada sob preservação é apagado: nem pela retenção, nem pela exclusão manual, nem pela
| exclusão da organização. O bloqueio vence e registra a tentativa; liberar exige permissão
| e fica na trilha.
*/

beforeEach(function () {
    Storage::fake('documents');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
    retentionEnable($this->organization);
});

it('preservação do documento vence a retenção; depois de liberada, a retenção apaga', function () {
    $held = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    $free = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    retentionPolicyFor($this->organization, ['completed' => 1825, 'identity_capture' => 30]);

    $hold = app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Ação judicial 0001234-56', envelope: $held['envelope']);

    $summary = app(RetentionRunner::class)->run();

    expect($summary['envelopes_purged'])->toBe(1)
        ->and($summary['held'])->toBeGreaterThanOrEqual(1)
        ->and(retentionEnvelopeGone($free['envelope']))->toBeTrue()
        ->and(Envelope::withoutOrganizationScope()->whereKey($held['envelope']->id)->exists())->toBeTrue()
        // A foto do preservado também fica (a varredura de fotos respeita o bloqueio).
        ->and($held['capture']->fresh()->storage_path)->not->toBeNull();

    foreach ($held['paths'] as $path) {
        expect(Storage::disk('documents')->exists($path))->toBeTrue();
    }

    expect(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::SKIPPED_BY_HOLD)->exists())->toBeTrue();

    app(LegalHolds::class)->release($hold, $this->owner, 'Processo arquivado');
    app(RetentionRunner::class)->run();

    expect(retentionEnvelopeGone($held['envelope']))->toBeTrue()
        ->and(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::HOLD_RELEASED)->where('legal_hold_id', $hold->id)->count())->toBe(1);
});

it('preservação de pasta cobre as subpastas; a da organização cobre tudo; a vencida não cobre nada', function () {
    $parent = Folder::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);
    $child = Folder::factory()->childOf($parent)->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);

    $inChild = retentionFinishedEnvelope($this->organization, $this->owner, 2000, attributes: ['folder_id' => $child->id]);
    retentionPolicyFor($this->organization, ['completed' => 1825]);

    $folderHold = app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Folder, 'Auditoria interna', folder: $parent);

    app(RetentionRunner::class)->run();
    expect(Envelope::withoutOrganizationScope()->whereKey($inChild['envelope']->id)->exists())->toBeTrue();

    app(LegalHolds::class)->release($folderHold, $this->owner, 'Auditoria concluída');
    $orgHold = app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Organization, 'Fiscalização');

    app(RetentionRunner::class)->run();
    expect(Envelope::withoutOrganizationScope()->whereKey($inChild['envelope']->id)->exists())->toBeTrue();

    // Bloqueio com data final vencida já não protege.
    DB::table('legal_holds')->where('id', $orgHold->id)->update(['ends_at' => now()->subDay()]);

    app(RetentionRunner::class)->run();
    expect(retentionEnvelopeGone($inChild['envelope']))->toBeTrue();
});

it('preservação vence a exclusão manual do rascunho e registra a tentativa com o autor', function () {
    $draft = readyEnvelope($this->organization, $this->owner);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Pedido do jurídico', envelope: $draft);

    actingAsMember($this->owner, $this->organization);

    $this->from(route('envelopes.index'))
        ->delete(route('envelopes.destroy', ['envelope' => $draft->ulid]))
        ->assertRedirect(route('envelopes.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'preservado'));

    expect(Envelope::withoutOrganizationScope()->whereKey($draft->id)->whereNull('deleted_at')->exists())->toBeTrue();

    $event = RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::HOLD_BLOCKED_DELETION)->firstOrFail();

    expect($event->actor_user_id)->toBe($this->owner->id)
        ->and($event->payload['context'])->toBe('manual_delete');
});

it('preservação vence a exclusão da organização: fica fora da lista, purge() recusa, nada é apagado', function () {
    $envelope = retentionFinishedEnvelope($this->organization, $this->owner, 10);
    $hold = app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Litígio trabalhista', envelope: $envelope['envelope']);

    OrganizationSettings::of($this->organization)->put(['deletion_requested_at' => now()->subDays(31)]);

    $purge = app(OrganizationPurge::class);

    expect($purge->due()->pluck('ulid')->all())->not->toContain($this->organization->ulid);

    expect(fn () => $purge->purge($this->organization->fresh()))->toThrow(LegalHoldActiveException::class);

    expect(Organization::query()->whereKey($this->organization->id)->exists())->toBeTrue()
        ->and(Envelope::withoutOrganizationScope()->whereKey($envelope['envelope']->id)->exists())->toBeTrue()
        ->and(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::HOLD_BLOCKED_DELETION)->where('payload->context', 'organization_purge')->exists())->toBeTrue();

    foreach ($envelope['paths'] as $path) {
        expect(Storage::disk('documents')->exists($path))->toBeTrue();
    }

    // Liberada a preservação, a exclusão agendada segue o curso normal — e leva as tabelas novas.
    app(LegalHolds::class)->release($hold, $this->owner, 'Acordo homologado');

    expect($purge->due()->pluck('ulid')->all())->toContain($this->organization->ulid);

    $purge->purge($this->organization->fresh());

    expect(Organization::withTrashed()->whereKey($this->organization->id)->exists())->toBeFalse()
        ->and(LegalHold::withoutOrganizationScope()->where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(RetentionEvent::withoutOrganizationScope()->where('organization_id', $this->organization->id)->count())->toBe(0);
});

it('preservar e liberar pela interface exige a permissão própria; operador não pode; motivo é obrigatório', function () {
    $envelope = retentionFinishedEnvelope($this->organization, $this->owner, 10)['envelope'];
    $member = attachMember($this->organization, MembershipRole::Member);
    $admin = attachMember($this->organization, MembershipRole::Admin);

    actingAsMember($member, $this->organization);
    $this->post(route('envelopes.legal_hold.store', ['envelope' => $envelope->ulid]), ['reason' => 'Tentativa do operador'])->assertForbidden();

    actingAsMember($admin, $this->organization);
    $this->post(route('envelopes.legal_hold.store', ['envelope' => $envelope->ulid]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('envelopes.legal_hold.store', ['envelope' => $envelope->ulid]), ['reason' => 'Notificação extrajudicial recebida'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $hold = LegalHold::withoutOrganizationScope()->where('envelope_id', $envelope->id)->firstOrFail();

    expect($hold->created_by_user_id)->toBe($admin->id)
        ->and($hold->scope)->toBe(LegalHoldScope::Envelope);

    $this->getJson(route('envelopes.legal_hold.show', ['envelope' => $envelope->ulid]))
        ->assertOk()
        ->assertJsonPath('preserved', true)
        ->assertJsonPath('holds.0.id', $hold->ulid)
        ->assertJsonPath('can.release', true);

    actingAsMember($member, $this->organization);
    $this->post(route('legal_holds.release', ['legalHold' => $hold->ulid]), ['reason' => 'Operador tentando liberar'])->assertForbidden();

    actingAsMember($this->owner, $this->organization);
    $this->post(route('legal_holds.release', ['legalHold' => $hold->ulid]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('legal_holds.release', ['legalHold' => $hold->ulid]), ['reason' => 'Prazo prescricional encerrado'])->assertSessionHas('success');

    expect($hold->fresh()->released_by_user_id)->toBe($this->owner->id)
        ->and(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::HOLD_PLACED)->count())->toBe(1)
        ->and(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::HOLD_RELEASED)->count())->toBe(1);
});

it('preservação de pasta e da organização pela tela de configurações', function () {
    $folder = Folder::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->owner->id]);

    actingAsMember($this->owner, $this->organization);

    $this->post(route('settings.retention.holds.store'), ['scope' => 'folder', 'reason' => 'Auditoria externa'])->assertSessionHasErrors('folder_id');
    $this->post(route('settings.retention.holds.store'), ['scope' => 'folder', 'folder_id' => $folder->ulid, 'reason' => 'Auditoria externa'])->assertSessionHas('success');
    $this->post(route('settings.retention.holds.store'), ['scope' => 'organization', 'reason' => 'Fiscalização da ANPD', 'ends_at' => now()->addMonths(6)->toDateString()])->assertSessionHas('success');

    expect(LegalHold::withoutOrganizationScope()->where('organization_id', $this->organization->id)->active()->count())->toBe(2);
});

it('isola as organizações: não se preserva nem se libera o que é de outra', function () {
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    retentionEnable($other);

    $envelope = retentionFinishedEnvelope($this->organization, $this->owner, 10)['envelope'];
    $hold = app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Litígio', envelope: $envelope);

    actingAsMember($otherOwner, $other);

    $this->post(route('envelopes.legal_hold.store', ['envelope' => $envelope->ulid]), ['reason' => 'Intrusão'])->assertNotFound();
    $this->post(route('legal_holds.release', ['legalHold' => $hold->ulid]), ['reason' => 'Intrusão'])->assertNotFound();
    $this->getJson(route('envelopes.legal_hold.show', ['envelope' => $envelope->ulid]))->assertNotFound();

    expect($hold->fresh()->released_at)->toBeNull();
});
