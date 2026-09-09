<?php

use App\Enums\EnvelopeStatus;
use App\Models\CertificateReference;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Verification/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — "Assinado" num envelope que não foi assinado
|--------------------------------------------------------------------------
| `EnvelopeStatus::Completed->label()` devolve "Assinado" sem olhar para
| `verification_records.signature_status`. Esse rótulo alimenta
| `EnvelopeDetailResource::status_label`, que por sua vez alimenta
|
|   - o badge do cabeçalho em `pages/envelopes/show.tsx`; e
|   - a linha "Estado" do bloco Identificação em `pages/envelopes/evidence.tsx`
|     (o controller repassa `status_label` via Arr::only).
|
| Num envelope concluído com `signature_status = none`, as duas telas dizem
| "Assinado" a poucos centímetros de um texto que diz o contrário — verificado no
| navegador em AV-00010 e AV-00006: badge verde "Assinado" logo acima do banner
| "Concluído com aceite eletrônico e evidências … o arquivo final não tem
| assinatura criptográfica", e "Estado: Assinado" logo abaixo de "Este documento
| foi concluído sem assinatura criptográfica".
|
| arquitetura.md §2 ("Sem certificado … a UI diz exatamente isso. Nunca simular
| assinatura criptográfica") tem precedência sobre o badge prescrito em
| ROUTES_AND_PAGES §6.1, pela ordem fixada em RECONCILIACAO.md. O próprio código
| já reconhece a contradição: `SignatureNarrative::statusLabel()` existe só para
| não repetir "Assinado" na página pública.
|
| O que a interface DEVE dizer continua aberto ("Concluído" é a sugestão do
| relatório de integração); o que ela não pode dizer é "Assinado" quando não há
| assinatura nenhuma.
*/

beforeEach(fn () => $this->withoutVite());

it('não chama de "Assinado" um envelope concluído sem assinatura criptográfica', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    finalizeEnvelope($envelope, 'none');

    actingAsMember($owner, $organization);

    $props = $this->get(route('envelopes.show', ['envelope' => $envelope->ulid]))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['envelope']['signature_status'])->toBe('none')
        ->and($props['envelope']['status_label'])->not->toBe('Assinado');
});

it('a página de evidências não repete "Assinado" no bloco de identificação', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    finalizeEnvelope($envelope, 'none');

    actingAsMember($owner, $organization);

    $props = $this->get(route('envelopes.evidence', ['envelope' => $envelope->ulid]))
        ->assertOk()
        ->viewData('page')['props'];

    // O statement da mesma página afirma o contrário do badge.
    expect($props['signature']['statement'])->toContain('sem assinatura criptográfica')
        ->and($props['envelope']['status_label'])->not->toBe('Assinado');
});

it('com certificado da operadora o rótulo pode dizer assinado — a distinção precisa existir', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $certificate = CertificateReference::factory()->active()->create();

    $signed = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    finalizeEnvelope($signed, 'company_a1', $certificate, validationSummary());

    $unsigned = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    finalizeEnvelope($unsigned, 'none');

    actingAsMember($owner, $organization);

    $labelFor = function (string $ulid): string {
        return $this->get(route('envelopes.show', ['envelope' => $ulid]))
            ->viewData('page')['props']['envelope']['status_label'];
    };

    // O ponto do achado: hoje os dois rótulos são idênticos.
    expect($labelFor($signed->ulid))->not->toBe($labelFor($unsigned->ulid));
});
