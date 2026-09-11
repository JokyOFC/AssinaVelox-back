<?php

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Jobs\Dossier\PurgeExpiredDossierExports;
use App\Models\AuditEvent;
use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/DossierHelpers.php';

/*
|--------------------------------------------------------------------------
| Dossiê: isolamento entre organizações, link com expiração e limpeza do arquivo
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
    ['organization' => $this->organization, 'owner' => $this->owner] = ktsaDossierSetup($this->work);
    $this->envelope = ktsaCompletedEnvelope($this->organization, $this->owner, $this->work)['envelope'];

    actingAsMember($this->owner, $this->organization);
    $id = $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertSuccessful()->json('export.id');
    $this->export = DossierExport::withoutOrganizationScope()->where('ulid', $id)->firstOrFail();
    $this->url = app(DossierExports::class)->statusProps($this->export)['download_url'];
});

afterEach(function () {
    ktsaCleanup($this->work ?? null);
});

it('o dossiê de outra organização é inacessível (404 no status, no download e no pedido)', function () {
    ['organization' => $other, 'owner' => $stranger] = createOrganizationWithOwner(['name' => 'Outra Empresa']);
    ktsaEnableDossier($other);
    actingAsMember($stranger, $other);

    $this->getJson(route('dossiers.show', ['dossierExport' => $this->export->ulid]))->assertNotFound();
    $this->get($this->url)->assertNotFound();
    $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertNotFound();
});

it('um membro sem acesso ao envelope não pede nem baixa o dossiê', function () {
    $member = attachMember($this->organization, MembershipRole::Member);
    actingAsMember($member, $this->organization);

    $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertForbidden();
    $this->getJson(route('dossiers.show', ['dossierExport' => $this->export->ulid]))->assertNotFound();
    $this->get($this->url)->assertNotFound();
});

it('o status traz o link assinado e o download registra envelope.downloaded (tipo dossier)', function () {
    $status = $this->getJson(route('dossiers.show', ['dossierExport' => $this->export->ulid]))->assertOk()->json('export');

    expect($status['status'])->toBe('ready')
        ->and($status['download_url'])->toContain('signature=')
        ->and($status['download_url'])->toContain('expires=');

    $this->get($status['download_url'])->assertOk();

    $event = AuditEvent::query()->where('envelope_id', $this->envelope->id)->where('event_type', AuditEventType::EnvelopeDownloaded->value)->latest('id')->first();

    expect($event->payload['type'])->toBe('dossier')
        ->and($event->payload['dossier_export_ulid'])->toBe($this->export->ulid)
        ->and($this->export->refresh()->download_count)->toBe(1);
});

it('link com assinatura adulterada é recusado (403)', function () {
    $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $this->url))->assertForbidden();
});

it('link expirado é recusado (410) e o arquivo é apagado', function () {
    $path = $this->export->storage_path;
    expect(Storage::disk('documents')->exists($path))->toBeTrue();

    $this->travel(25)->hours();

    $this->get($this->url)->assertStatus(410);

    $export = $this->export->refresh();
    expect($export->status)->toBe('expired')
        ->and($export->purged_at)->not->toBeNull()
        ->and($export->storage_path)->toBeNull()
        ->and(Storage::disk('documents')->exists($path))->toBeFalse();

    expect(app(DossierExports::class)->statusProps($export)['download_url'])->toBeNull();
});

it('o job de limpeza apaga só o que venceu', function () {
    $path = $this->export->storage_path;

    (new PurgeExpiredDossierExports)->handle(app(DossierExports::class));
    expect(Storage::disk('documents')->exists($path))->toBeTrue();

    $this->travel(2)->days();
    (new PurgeExpiredDossierExports)->handle(app(DossierExports::class));

    expect(Storage::disk('documents')->exists($path))->toBeFalse()
        ->and($this->export->refresh()->status)->toBe('expired');
});

it('pedir de novo depois de expirar remonta o mesmo pedido', function () {
    $this->travel(2)->days();
    (new PurgeExpiredDossierExports)->handle(app(DossierExports::class));

    $id = $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertSuccessful()->json('export.id');

    expect($id)->toBe($this->export->ulid)
        ->and($this->export->refresh()->status)->toBe('ready')
        ->and(Storage::disk('documents')->exists((string) $this->export->storage_path))->toBeTrue();
});
