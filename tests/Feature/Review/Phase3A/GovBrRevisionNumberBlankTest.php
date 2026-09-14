<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Signing\GovBr\ExternalSignatureRequestStatus;
use App\Services\Signing\GovBr\GovBrSignatureKind;
use App\Services\Signing\GovBr\GovBrSignatureViews;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 3, parte 1 (design) — "revisão nº" sem número na devolução gov.br
|--------------------------------------------------------------------------
| No detalhe do envelope e na página de evidências (lista "Assinaturas com o certificado do
| próprio participante"), a linha do documento devolvido pelo portal aparece como
|     "1. contrato · revisão nº"
| — sem o número —, logo abaixo da linha do componente local, que mostra "· revisão nº 1".
| Visto no navegador no envelope misto do QA (componente simulado + devolução).
|
| Causa: GovBrSignatureViews::forEvidence() grava `'revision_index' => null`
| (app/Services/Signing/GovBr/GovBrSignatureViews.php:80) e o componente imprime o rótulo sem
| condição (resources/js/components/verification/crypto-signature-list.tsx:248-249).
|
| O teste aceita qualquer das duas correções: o servidor informar o número da revisão OU o
| componente deixar de imprimir "revisão nº" quando ele não vier.
*/

it('a devolução gov.br não aparece como "revisão nº" sem número', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create(['title' => 'Contrato']);
    $document = Document::factory()->forEnvelope($envelope)->create(['name' => 'contrato']);
    $signed = DocumentVersion::factory()->forDocument($document)->create();
    $recipient = Recipient::factory()->forEnvelope($envelope, 1)->create(['name' => 'João Lima', 'email' => 'joao@exemplo.test']);

    ExternalSignatureRequest::query()->create([
        'organization_id' => $organization->getKey(),
        'envelope_id' => $envelope->getKey(),
        'recipient_id' => $recipient->getKey(),
        'document_id' => $document->getKey(),
        'status' => ExternalSignatureRequestStatus::Completed->value,
        'signed_document_version_id' => $signed->getKey(),
        'signature_kind' => GovBrSignatureKind::ParticipantExternalUnverified->value,
        'field_name' => 'AV_GOVBR_teste',
        'holder_name' => 'Joao Lima TESTE',
        'signer_issuer' => 'CN=AC TESTE',
        'is_test_certificate' => true,
        'completed_at' => now(),
    ]);

    $views = GovBrSignatureViews::forEvidence($envelope);

    expect($views)->toHaveCount(1);

    $index = $views[0]['documents'][0]['revision_index'];

    $component = (string) file_get_contents(base_path('resources/js/components/verification/crypto-signature-list.tsx'));
    // O componente só estaria correto se escondesse o rótulo quando o número não vem.
    $guarded = preg_match('/revision_index\s*(?:!==?\s*null|&&|\?\?|\?\s)/', $component) === 1;

    expect(is_int($index) && $index > 0 || $guarded)
        ->toBeTrue('A linha da devolução gov.br mostra "revisão nº" sem número: revision_index = '.var_export($index, true).' e o componente imprime o rótulo sem condição.');
});
