<?php

use App\Models\Envelope;
use App\Models\RetentionDeletion;
use App\Services\Retention\EnvelopePurger;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use App\Services\Retention\RetentionRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/Support/RetentionHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.19 (K-RET) — propagação: capturas, dossiês e derivados
|--------------------------------------------------------------------------
| A tabela de dossiês real é de outro item (§2.13); aqui o mecanismo é provado com uma
| tabela de teste registrada em `assinavelox.retention.derived_artifacts`.
*/

beforeEach(function () {
    Storage::fake('documents');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    retentionEnable($this->organization);

    Schema::create('retention_test_dossiers', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('organization_id');
        $table->foreignId('envelope_id')->nullable();
        $table->string('storage_path')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    config()->set('assinavelox.retention.derived_artifacts', [
        ['table' => 'retention_test_dossiers', 'path_columns' => ['storage_path'], 'date_column' => 'created_at', 'category' => 'dossier'],
    ]);
    EnvelopePurger::forgetSchemaCache();
});

afterEach(function () {
    EnvelopePurger::forgetSchemaCache();
});

function dossierRow(int $organizationId, ?int $envelopeId, int $daysAgo): array
{
    $path = 'dossiers/'.Str::ulid().'.zip';
    Storage::disk('documents')->put($path, 'PK');
    $id = DB::table('retention_test_dossiers')->insertGetId([
        'organization_id' => $organizationId,
        'envelope_id' => $envelopeId,
        'storage_path' => $path,
        'created_at' => now()->subDays($daysAgo),
    ]);

    return ['id' => $id, 'path' => $path];
}

it('fotos da captura: o arquivo sai pelo prazo da categoria; a linha fica com purged_at; o envelope fica', function () {
    $ctx = retentionFinishedEnvelope($this->organization, $this->owner, 40);
    $held = retentionFinishedEnvelope($this->organization, $this->owner, 40);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Perícia', envelope: $held['envelope']);

    retentionPolicyFor($this->organization, ['identity_capture' => 30]);

    $summary = app(RetentionRunner::class)->run();

    $capture = $ctx['capture']->fresh();

    expect($summary['identity_captures'])->toBe(1)
        ->and($capture->storage_path)->toBeNull()
        ->and($capture->purged_at)->not->toBeNull()
        ->and(Envelope::withoutOrganizationScope()->whereKey($ctx['envelope']->id)->exists())->toBeTrue()
        ->and($held['capture']->fresh()->storage_path)->not->toBeNull()
        ->and(RetentionDeletion::withoutOrganizationScope()->where('subject_type', 'identity_captures')->count())->toBe(1);

    expect(collect(Storage::disk('documents')->allFiles())->filter(fn (string $p): bool => str_contains($p, '/identity/'))->count())->toBe(1);
});

it('dossiês: o arquivo e a linha saem pelo prazo; os de envelope preservado ficam', function () {
    $ctx = retentionFinishedEnvelope($this->organization, $this->owner, 10);
    $held = retentionFinishedEnvelope($this->organization, $this->owner, 10);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Perícia', envelope: $held['envelope']);

    $old = dossierRow($this->organization->id, $ctx['envelope']->id, 20);
    $bulk = dossierRow($this->organization->id, null, 20);
    $recent = dossierRow($this->organization->id, $ctx['envelope']->id, 2);
    $protected = dossierRow($this->organization->id, $held['envelope']->id, 20);

    retentionPolicyFor($this->organization, ['dossier' => 7]);

    $summary = app(RetentionRunner::class)->run();
    $disk = Storage::disk('documents');

    expect($summary['dossiers'])->toBe(2)
        ->and($disk->exists($old['path']))->toBeFalse()
        ->and($disk->exists($bulk['path']))->toBeFalse()
        ->and($disk->exists($recent['path']))->toBeTrue()
        ->and($disk->exists($protected['path']))->toBeTrue()
        ->and(DB::table('retention_test_dossiers')->pluck('id')->sort()->values()->all())->toBe(collect([$recent['id'], $protected['id']])->sort()->values()->all());
});

it('a exclusão do envelope leva os artefatos derivados dele (linha e arquivo), fora do diretório do envelope', function () {
    $ctx = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    $derived = dossierRow($this->organization->id, $ctx['envelope']->id, 1);
    $other = dossierRow($this->organization->id, null, 1);

    retentionPolicyFor($this->organization, ['completed' => 1825]);

    app(RetentionRunner::class)->run();

    expect(retentionEnvelopeGone($ctx['envelope']))->toBeTrue()
        ->and(Storage::disk('documents')->exists($derived['path']))->toBeFalse()
        ->and(DB::table('retention_test_dossiers')->where('id', $derived['id'])->exists())->toBeFalse()
        ->and(DB::table('retention_test_dossiers')->where('id', $other['id'])->exists())->toBeTrue()
        ->and(RetentionDeletion::withoutOrganizationScope()->where('subject_ulid', $ctx['envelope']->ulid)->value('manifest')['counts']['retention_test_dossiers'] ?? null)->toBe(1);
});

it('trilha de auditoria: só com a operadora permitindo, e só eventos sem documento', function () {
    $kept = retentionFinishedEnvelope($this->organization, $this->owner, 10);
    $orphanEvent = DB::table('audit_events')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'organization_id' => $this->organization->id,
        'envelope_id' => null,
        'actor_type' => 'system',
        'event_type' => 'role.created',
        'occurred_at' => now()->subDays(4000),
        'created_at' => now()->subDays(4000),
    ]);
    DB::table('audit_events')->where('id', $kept['audit_event']->id)->update(['occurred_at' => now()->subDays(4000)]);

    retentionPolicyFor($this->organization, ['audit_trail' => 1825]);

    // Operadora não permitiu: nada sai.
    app(RetentionRunner::class)->run();
    expect(DB::table('audit_events')->where('id', $orphanEvent)->exists())->toBeTrue();

    config()->set('assinavelox.retention.allow_audit_trail_deletion', true);
    $summary = app(RetentionRunner::class)->run();

    expect($summary['audit_events'])->toBe(1)
        ->and(DB::table('audit_events')->where('id', $orphanEvent)->exists())->toBeFalse()
        // Evento de envelope que ainda existe nunca sai por esta categoria.
        ->and(DB::table('audit_events')->where('id', $kept['audit_event']->id)->exists())->toBeTrue();
});
