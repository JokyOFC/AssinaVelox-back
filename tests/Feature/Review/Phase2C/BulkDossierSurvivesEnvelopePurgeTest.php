<?php

use App\Models\RetentionDeletion;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Retention\RetentionRunner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase2/Retention/Support/RetentionHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C (ciclo de vida) — dossiê em lote sobrevive à exclusão
|--------------------------------------------------------------------------
| RetentionCategory::Completed->deletes() promete apagar "Dossiês gerados, carimbos do tempo e
| demais derivados do documento", e o recibo sai `completed`. EnvelopePurger só alcança os
| derivados por `envelope_id` (RetentionConfig::derivedArtifacts()). O dossiê EM LOTE
| (BulkDossierBuilder) grava `envelope_id = null` e os ids em `envelope_ids`, e o ZIP fica em
| `orgs/{org}/dossiers/{ulid}.zip`, fora do diretório do envelope: depois da exclusão, o ZIP
| com o PDF final, as evidências e a trilha (nomes, e-mails, IPs) do documento "excluído"
| continua no disco e baixável até o link vencer, e a linha continua apontando para um
| envelope que não existe mais.
*/

beforeEach(function () {
    Storage::fake('documents');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    retentionEnable($this->organization);
});

it('a exclusão do envelope pela retenção leva também o dossiê em lote que o contém', function () {
    $ctx = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    $recent = retentionFinishedEnvelope($this->organization, $this->owner, 10);

    /** @var DossierExport $bulk */
    $bulk = DossierExport::withoutOrganizationScope()->create([
        'organization_id' => $this->organization->id,
        'requested_by_user_id' => $this->owner->id,
        'kind' => DossierExport::KIND_BULK,
        'envelope_ids' => [$ctx['envelope']->id, $recent['envelope']->id],
        'envelope_count' => 2,
        'idempotency_key' => 'bulk:review-c2:'.Str::ulid(),
        'status' => DossierExport::STATUS_READY,
        'storage_disk' => 'documents',
        'completed_at' => now(),
        'expires_at' => now()->addHours(20),
    ]);
    $path = sprintf('orgs/%s/dossiers/%s.zip', $this->organization->ulid, $bulk->ulid);
    Storage::disk('documents')->put($path, 'PK final.pdf evidencias.pdf trilha.csv');
    $bulk->forceFill(['storage_path' => $path])->save();

    retentionPolicyFor($this->organization, ['completed' => 1825]);

    app(RetentionRunner::class)->run();

    $receipt = RetentionDeletion::withoutOrganizationScope()->where('subject_ulid', $ctx['envelope']->ulid)->sole();

    expect(retentionEnvelopeGone($ctx['envelope']))->toBeTrue()
        ->and($receipt->status)->toBe(RetentionDeletion::STATUS_COMPLETED);

    // O ZIP com o conteúdo do envelope excluído não pode continuar no disco.
    expect(Storage::disk('documents')->exists($path))->toBeFalse();

    // E nenhum pedido de dossiê pronto pode continuar apontando para o envelope excluído.
    $stillPointing = DossierExport::withoutOrganizationScope()
        ->where('status', DossierExport::STATUS_READY)
        ->get()
        ->filter(fn (DossierExport $export): bool => in_array((int) $ctx['envelope']->id, $export->envelopeIds(), true));

    expect($stillPointing)->toHaveCount(0);
});
