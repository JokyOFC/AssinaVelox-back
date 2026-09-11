<?php

use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\RetentionDeletion;
use App\Models\RetentionEvent;
use App\Models\RetentionRun;
use App\Services\Retention\RetentionRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/RetentionHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.19 (K-RET) — o job de retenção apaga exatamente o que deve
|--------------------------------------------------------------------------
| Disco e banco; nada além do vencido; recibo sem dado pessoal; trilha preservada; job
| repetido não falha nem duplica; retomada de execução interrompida; isolamento.
*/

beforeEach(function () {
    Storage::fake('documents');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
    retentionEnable($this->organization);
});

it('apaga o envelope concluído vencido — arquivos, versões, participantes, aceites, fotos e registro público — e só ele', function () {
    $old = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    $recent = retentionFinishedEnvelope($this->organization, $this->owner, 30);
    retentionPolicyFor($this->organization, ['completed' => 1825]);

    $this->artisan('retention:apply')->assertSuccessful();

    $disk = Storage::disk('documents');

    expect(retentionEnvelopeGone($old['envelope']))->toBeTrue()
        ->and($old['paths'])->not->toBeEmpty();

    foreach ($old['paths'] as $path) {
        expect($disk->exists($path))->toBeFalse();
    }

    expect($disk->allFiles('orgs/'.$this->organization->ulid.'/envelopes/'.$old['envelope']->ulid))->toBe([]);

    // O recente continua inteiro, banco e disco.
    expect(Envelope::withoutOrganizationScope()->whereKey($recent['envelope']->id)->exists())->toBeTrue();

    foreach ($recent['paths'] as $path) {
        expect($disk->exists($path))->toBeTrue();
    }

    $receipt = RetentionDeletion::withoutOrganizationScope()->where('subject_ulid', $old['envelope']->ulid)->firstOrFail();

    expect($receipt->status)->toBe('completed')
        ->and($receipt->category)->toBe('completed')
        ->and($receipt->pending_paths)->toBeNull()
        ->and($receipt->final_hashes)->toBe([$old['final_sha256']])
        ->and($receipt->manifest['counts']['files'])->toBe(count($old['paths']))
        ->and($receipt->manifest['counts']['recipients'])->toBe(1)
        ->and(json_encode($receipt->toArray()))->not->toContain('Contrato de locação')
        ->and(json_encode($receipt->toArray()))->not->toContain('maria@exemplo.test');

    // A trilha fica (sem o vínculo com o envelope, que a FK anula) — nenhum UPDATE da aplicação.
    $event = AuditEvent::withoutOrganizationScope()->whereKey($old['audit_event']->id)->first();

    expect($event)->not->toBeNull()
        ->and($event->envelope_id)->toBeNull()
        ->and(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::ENVELOPE_PURGED)->where('subject_ulid', $old['envelope']->ulid)->count())->toBe(1);
});

it('é idempotente: rodar de novo não falha, não duplica recibo nem evento', function () {
    $old = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    retentionPolicyFor($this->organization, ['completed' => 1825]);

    $this->artisan('retention:apply')->assertSuccessful();
    $this->artisan('retention:apply')->assertSuccessful();
    $second = app(RetentionRunner::class)->run();

    expect($second['envelopes_purged'])->toBe(0)
        ->and($second['failed'])->toBe(0)
        ->and(RetentionDeletion::withoutOrganizationScope()->where('subject_ulid', $old['envelope']->ulid)->count())->toBe(1)
        ->and(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::ENVELOPE_PURGED)->count())->toBe(1)
        ->and(RetentionRun::query()->where('status', 'completed')->count())->toBe(3);
});

it('retoma um recibo pendente de execução interrompida entre o commit e a remoção dos arquivos', function () {
    $orphan = 'orgs/'.$this->organization->ulid.'/envelopes/01JZZZZZZZZZZZZZZZZZZZZZZZ/v.pdf';
    Storage::disk('documents')->put($orphan, '%PDF');

    $pending = RetentionDeletion::query()->create([
        'organization_id' => $this->organization->id,
        'category' => 'completed',
        'subject_type' => 'envelope',
        'subject_ulid' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        'pending_paths' => [$orphan],
        'status' => 'pending',
    ]);

    $summary = app(RetentionRunner::class)->run();

    expect($summary['resumed'])->toBe(1)
        ->and(Storage::disk('documents')->exists($orphan))->toBeFalse()
        ->and($pending->fresh()->status)->toBe('completed')
        ->and($pending->fresh()->pending_paths)->toBeNull();
});

it('aplica recusados/expirados/cancelados e rascunhos (inclusive excluídos), mas nunca envelopes em andamento', function () {
    $refused = retentionFinishedEnvelope($this->organization, $this->owner, 400, EnvelopeStatus::Refused);
    $running = retentionFinishedEnvelope($this->organization, $this->owner, 400, EnvelopeStatus::InProgress);

    $draft = readyEnvelope($this->organization, $this->owner);
    $draft->delete();
    DB::table('envelopes')->where('id', $draft->id)->update(['deleted_at' => now()->subDays(60), 'updated_at' => now()->subDays(60)]);
    $draftVersion = DocumentVersion::withoutOrganizationScope()->where('organization_id', $this->organization->id)
        ->whereIn('document_id', DB::table('documents')->where('envelope_id', $draft->id)->pluck('id'))->firstOrFail();
    Storage::disk('documents')->put($draftVersion->storage_path, '%PDF rascunho');

    $freshDraft = readyEnvelope($this->organization, $this->owner);

    retentionPolicyFor($this->organization, ['terminal_other' => 180, 'draft' => 30]);

    $summary = app(RetentionRunner::class)->run();

    expect($summary['envelopes_purged'])->toBe(2)
        ->and(retentionEnvelopeGone($refused['envelope']))->toBeTrue()
        ->and(retentionEnvelopeGone($draft))->toBeTrue()
        ->and(Storage::disk('documents')->exists($draftVersion->storage_path))->toBeFalse()
        ->and(Envelope::withoutOrganizationScope()->whereKey($running['envelope']->id)->exists())->toBeTrue()
        ->and(Envelope::withoutOrganizationScope()->whereKey($freshDraft->id)->exists())->toBeTrue();

    // Rascunho nunca publicado: recibo sem código (a verificação continua "não existe").
    expect(RetentionDeletion::withoutOrganizationScope()->where('subject_ulid', $draft->ulid)->value('verification_code'))->toBeNull();
});

it('nunca aplica abaixo do mínimo da operadora, mesmo com um prazo menor gravado direto no banco', function () {
    $old = retentionFinishedEnvelope($this->organization, $this->owner, 400);
    retentionPolicyFor($this->organization, ['completed' => 10]);

    app(RetentionRunner::class)->run();

    expect(Envelope::withoutOrganizationScope()->whereKey($old['envelope']->id)->exists())->toBeTrue();
});

it('simulação (--dry-run) conta e não apaga nada', function () {
    $old = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    retentionPolicyFor($this->organization, ['completed' => 1825, 'identity_capture' => 30]);

    $this->artisan('retention:apply', ['--dry-run' => true])
        ->expectsOutputToContain('[simulação]')
        ->assertSuccessful();

    expect(Envelope::withoutOrganizationScope()->whereKey($old['envelope']->id)->exists())->toBeTrue()
        ->and(RetentionDeletion::withoutOrganizationScope()->count())->toBe(0)
        ->and(RetentionRun::query()->count())->toBe(0);

    foreach ($old['paths'] as $path) {
        expect(Storage::disk('documents')->exists($path))->toBeTrue();
    }
});

it('isola as organizações: a política de uma não toca a outra, nem com --organization', function () {
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner(['name' => 'Outra Empresa']);
    retentionEnable($other);

    $mine = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    $theirs = retentionFinishedEnvelope($other, $otherOwner, 2000);

    retentionPolicyFor($this->organization, ['completed' => 1825]);
    retentionPolicyFor($other, ['completed' => 1825], active: false);

    $this->artisan('retention:apply', ['--organization' => $this->organization->ulid])->assertSuccessful();

    expect(retentionEnvelopeGone($mine['envelope']))->toBeTrue()
        ->and(Envelope::withoutOrganizationScope()->whereKey($theirs['envelope']->id)->exists())->toBeTrue();

    foreach ($theirs['paths'] as $path) {
        expect(Storage::disk('documents')->exists($path))->toBeTrue();
    }

    // Política inativa: nem o job geral toca.
    $this->artisan('retention:apply')->assertSuccessful();

    expect(Envelope::withoutOrganizationScope()->whereKey($theirs['envelope']->id)->exists())->toBeTrue();
});
