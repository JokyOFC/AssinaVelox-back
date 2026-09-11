<?php

use App\Jobs\Dossier\PurgeExpiredDossierExports;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use App\Services\Retention\RetentionRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase2/Retention/Support/RetentionHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C (ciclo de vida) — dossiê x preservação
|--------------------------------------------------------------------------
| docs/fase-2/retencao-e-preservacao.md §5.2 ("Uma regra só (LegalHolds), consultada por todos
| os caminhos que apagam") e §5.5 ("Expiração própria dos dossiês ... se ela apagar arquivos,
| deve consultar os bloqueios pelo mesmo contrato"). O relatório I-2C §3 afirma: "O envelope
| preservado não perde nada, inclusive o .tsr e o ZIP".
|
| Dois caminhos apagam o ZIP de um documento preservado:
|  1. a expiração própria do dossiê (PurgeExpiredDossierExports → DossierExports::purge), que
|     não consulta LegalHolds em nada;
|  2. a categoria "Dossiês" da retenção (CategorySweeper::dossiers), que só exclui da consulta
|     `envelope_id` coberto — o dossiê EM LOTE tem `envelope_id = null` e os envelopes em
|     `envelope_ids`, então passa pelo `whereNull(envelope_id)` mesmo contendo o preservado.
*/

beforeEach(function () {
    Storage::fake('documents');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
});

if (! function_exists('reviewC2DossierExport')) {
    /**
     * Pedido de dossiê PRONTO com o ZIP no disco, no mesmo layout de BuildDossierExport.
     *
     * @param  array<string, mixed>  $attributes
     */
    function reviewC2DossierExport(Organization $organization, User $owner, array $attributes, ?int $createdDaysAgo = null): DossierExport
    {
        $key = (string) Str::ulid();

        /** @var DossierExport $export */
        $export = DossierExport::withoutOrganizationScope()->create(array_merge([
            'organization_id' => $organization->id,
            'requested_by_user_id' => $owner->id,
            'idempotency_key' => 'review-c2:'.$key,
            'status' => DossierExport::STATUS_READY,
            'envelope_count' => 1,
            'storage_disk' => 'documents',
            'completed_at' => now(),
            'expires_at' => now()->addHours(20),
        ], $attributes));

        $path = sprintf('orgs/%s/dossiers/%s.zip', $organization->ulid, $export->ulid);
        Storage::disk('documents')->put($path, 'PK dossie com PDFs e trilha');
        $export->forceFill(['storage_path' => $path])->save();

        if ($createdDaysAgo !== null) {
            DB::table('dossier_exports')->where('id', $export->id)->update(['created_at' => now()->subDays($createdDaysAgo)]);
        }

        return $export->fresh();
    }
}

it('a expiração do dossiê não apaga o ZIP de um documento preservado (bloqueio do documento)', function () {
    $held = retentionFinishedEnvelope($this->organization, $this->owner, 10);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Ação judicial 0001234-56', envelope: $held['envelope']);

    $export = reviewC2DossierExport($this->organization, $this->owner, [
        'kind' => DossierExport::KIND_SINGLE,
        'envelope_id' => $held['envelope']->id,
    ]);

    // O prazo do link venceu (24 h por padrão): a limpeza agendada de hora em hora roda.
    $this->travel(2)->days();
    (new PurgeExpiredDossierExports)->handle(app(DossierExports::class));

    expect(Storage::disk('documents')->exists((string) $export->storage_path))->toBeTrue()
        ->and($export->fresh()->purged_at)->toBeNull();
});

it('a expiração do dossiê não apaga o ZIP quando a organização inteira está preservada', function () {
    $held = retentionFinishedEnvelope($this->organization, $this->owner, 10);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Organization, 'Fiscalização da ANPD');

    $export = reviewC2DossierExport($this->organization, $this->owner, [
        'kind' => DossierExport::KIND_SINGLE,
        'envelope_id' => $held['envelope']->id,
    ]);

    $this->travel(2)->days();
    (new PurgeExpiredDossierExports)->handle(app(DossierExports::class));

    expect(Storage::disk('documents')->exists((string) $export->storage_path))->toBeTrue();
});

it('a categoria "Dossiês" da retenção não apaga o dossiê em lote que contém um documento preservado', function () {
    retentionEnable($this->organization);

    $held = retentionFinishedEnvelope($this->organization, $this->owner, 10);
    $free = retentionFinishedEnvelope($this->organization, $this->owner, 10);
    app(LegalHolds::class)->place($this->organization, $this->owner, LegalHoldScope::Envelope, 'Perícia judicial', envelope: $held['envelope']);

    // Lote com o preservado e outro documento: envelope_id nulo, ids em envelope_ids.
    $bulk = reviewC2DossierExport($this->organization, $this->owner, [
        'kind' => DossierExport::KIND_BULK,
        'envelope_id' => null,
        'envelope_ids' => [$held['envelope']->id, $free['envelope']->id],
        'envelope_count' => 2,
        // Sem expiração própria para isolar o caminho da retenção.
        'expires_at' => now()->addYears(1),
    ], createdDaysAgo: 20);

    retentionPolicyFor($this->organization, ['dossier' => 7]);

    app(RetentionRunner::class)->run();

    expect(Storage::disk('documents')->exists((string) $bulk->storage_path))->toBeTrue()
        ->and(DossierExport::withoutOrganizationScope()->whereKey($bulk->id)->exists())->toBeTrue();
});
