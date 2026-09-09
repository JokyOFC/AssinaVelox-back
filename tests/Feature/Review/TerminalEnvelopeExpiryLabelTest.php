<?php

use App\Enums\EnvelopeStatus;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Verification/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — prazo em contagem regressiva num documento terminado
|--------------------------------------------------------------------------
| `EnvelopeDetailResource::expiresLabel()` só trata dois casos: `Expired` e
| "data no passado". Para qualquer outro status ele produz "Expira em {data}
| ({n} dias)" — inclusive para `completed`, `refused` e `canceled`, onde não há
| mais prazo nenhum a correr.
|
| Observado no navegador no AV-00010 e no AV-00006, ambos concluídos:
| "AV-00010 · Locações · Criado por … · Expira em 02 out (23 dias)" na linha de
| metadados logo abaixo do título, ao lado do banner "Concluído com aceite
| eletrônico e evidências". Num envelope recusado o mesmo rótulo promete um
| prazo para uma coleta que foi encerrada.
|
| ROUTES_AND_PAGES §2.7 usa `expires_label` para "Expira em 15 set (12 dias)"
| descrevendo um documento **aguardando assinatura**; o mock App - Documento
| mostra o rótulo em âmbar exatamente nesse contexto.
*/

beforeEach(fn () => $this->withoutVite());

it('não mostra contagem regressiva de prazo num envelope concluído', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    $envelope->forceFill(['expires_at' => now()->addDays(20)->endOfDay()])->save();
    finalizeEnvelope($envelope, 'none');

    actingAsMember($owner, $organization);

    $props = $this->get(route('envelopes.show', ['envelope' => $envelope->ulid]))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['envelope']['status'])->toBe('completed')
        ->and($props['envelope']['expires_label'] ?? '')->not->toContain('Expira em');
});

it('não mostra contagem regressiva de prazo num envelope recusado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Refused, [
        ['name' => 'Maria Aparecida Silva', 'email' => 'maria@exemplo.test', 'signed' => false],
    ]);
    $envelope->forceFill(['expires_at' => now()->addDays(20)->endOfDay()])->save();

    actingAsMember($owner, $organization);

    $props = $this->get(route('envelopes.show', ['envelope' => $envelope->ulid]))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['envelope']['status'])->toBe('refused')
        ->and($props['envelope']['expires_label'] ?? '')->not->toContain('Expira em');
});

it('continua mostrando o prazo enquanto a coleta corre', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::InProgress, [
        ['name' => 'Maria Aparecida Silva', 'email' => 'maria@exemplo.test', 'signed' => false],
    ]);
    $envelope->forceFill(['expires_at' => now()->addDays(20)->endOfDay()])->save();

    actingAsMember($owner, $organization);

    $props = $this->get(route('envelopes.show', ['envelope' => $envelope->ulid]))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['envelope']['expires_label'])->toContain('Expira em');
});
