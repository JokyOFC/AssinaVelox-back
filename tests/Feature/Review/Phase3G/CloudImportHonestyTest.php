<?php

use App\Models\CloudImport;
use App\Services\CloudImport\CloudProvider;

require_once __DIR__.'/../../Phase3/Connectors/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda G (produto/semântica) — importar da nuvem
|--------------------------------------------------------------------------
| 1. Wizard, passo 1: com `cloud_import` ligada e NENHUM app registrado (Google e Dropbox
|    "Aguardando app registrado pelo proprietário"), o cartão "Importar do Google Drive ou do
|    Dropbox · O arquivo passa pelas mesmas verificações de um envio do computador." e o botão
|    "Importar da nuvem" aparecem como se a importação existisse. A página seguinte diz
|    "Ainda não disponível" nos dois provedores. O mesmo vale para "Google Drive e Dropbox:
|    importe arquivos no passo “Documento” de cada envio." em API e integrações. O cartão só
|    olha a flag (CloudImportEntry/ConnectorsCallout), nunca a disponibilidade dos provedores.
|
| 2. "Importações deste documento": uma recusa aparece como "Arquivo não importado ·
|    Google Drive — Recusado (file_too_large)". O código interno em snake_case vai cru para a
|    interface em PT-BR, e o nome do arquivo recusado nunca é gravado (original_filename null),
|    então quem importou vários arquivos não sabe qual falhou nem por quê.
*/

beforeEach(fn () => $this->withoutVite());

it('o wizard não anuncia a importação da nuvem quando nenhum provedor está disponível', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    connectorsEnable($organization);
    connectorsConfigure(google: false, dropbox: false, hubspot: false);
    $envelope = connectorsDraft($organization, $owner);

    actingAsMember($owner, $organization);

    // A página de importação reconhece que nada está disponível…
    $import = $this->get(route('cloud_import.show', $envelope))->assertOk()->viewData('page');
    expect($import['props']['providers']['google_drive']['available'])->toBeFalse()
        ->and($import['props']['providers']['dropbox']['available'])->toBeFalse();

    // …mas o wizard recebe só a flag e o cartão promete a importação.
    $wizard = $this->get(route('envelopes.edit', $envelope))->assertOk()->viewData('page');
    $entry = (string) file_get_contents(base_path('resources/js/components/integrations/cloud/cloud-import-entry.tsx'));

    $announced = ($wizard['props']['features']['cloud_import'] ?? false) === true
        && preg_match('/available|dispon[íi]vel|aguardando/iu', $entry) !== 1;

    expect($announced)->toBeFalse(
        'Wizard mostra "Importar do Google Drive ou do Dropbox" + "Importar da nuvem" com os dois provedores "Aguardando app registrado pelo proprietário".',
    );
});

it('uma importação recusada diz o arquivo e o motivo em português, não o código interno', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    connectorsEnable($organization);
    $envelope = connectorsDraft($organization, $owner);

    CloudImport::query()->create([
        'organization_id' => $organization->getKey(),
        'envelope_id' => $envelope->getKey(),
        'provider' => CloudProvider::GoogleDrive,
        'external_id' => 'arquivo-grande',
        'status' => CloudImport::STATUS_REJECTED,
        'rejection_code' => 'file_too_large',
        'simulated' => false,
        'imported_by_user_id' => $owner->getKey(),
    ]);

    actingAsMember($owner, $organization);

    $row = $this->get(route('cloud_import.show', $envelope))->assertOk()->viewData('page')['props']['recent'][0];

    $readable = collect($row)
        ->except(['id', 'provider', 'provider_label', 'status', 'code', 'created_at', 'simulated', 'name'])
        ->filter(fn ($value) => is_string($value) && preg_match('/\p{Ll}+ \p{Ll}+/u', $value) === 1);

    expect($readable)->not->toBeEmpty(
        'A linha recusada chega à tela só com code="file_too_large"; cloud-import.tsx exibe "Recusado (file_too_large)" e "Arquivo não importado".',
    );
});
