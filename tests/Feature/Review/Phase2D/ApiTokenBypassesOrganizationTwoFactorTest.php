<?php

use App\Models\Envelope;

require_once __DIR__.'/../../Phase2/Api/Support/ApiHelpers.php';

/*
| Revisão adversarial — Fase 2, onda D (lente: segurança da API).
|
| docs/fase-2/api-v1.md §2.3: "O token nunca excede o criador" — o token age como a pessoa,
| com as permissões ATUAIS dela. docs/autorizacao-e-isolamento.md: `org.2fa` bloqueia todo o
| grupo `app` para quem não tem TOTP quando a organização exige 2FA.
|
| Defeito: App\Http\Middleware\ApiAuthenticate::resolve() confere membership ativa, conta
| bloqueada e organização, mas NÃO a política "Exigir autenticação em duas etapas". Um token
| criado antes de a política ser ligada (ou por quem desativou o 2FA depois — `two-factor.disable`
| é rota liberada pelo próprio EnforceTwoFactorForOrganization) continua lendo, criando e
| enviando documentos, enquanto a mesma pessoa está barrada em todas as telas.
*/

test('organização que exige 2FA: criador sem TOTP é barrado na interface E pela API', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    apiEnable($organization);
    $token = apiIssueToken($organization, $owner);

    Envelope::factory()->forOrganization($organization, $owner)->draft()->create(['title' => 'Contrato sigiloso']);

    // A organização passa a exigir 2FA; o proprietário não tem TOTP.
    $organization->forceFill(['settings' => array_merge($organization->settings ?? [], ['require_two_factor' => true])])->save();

    // Interface: barrado (controle — o comportamento existente).
    actingAsMember($owner, $organization);
    $this->get(route('envelopes.index'))->assertRedirect(route('security.edit'));
    auth()->guard()->logout();

    // API: a mesma pessoa, pelo token, não pode fazer mais do que na interface.
    $response = $this->getJson('/api/v1/envelopes', apiHeaders($token));

    expect($response->status())->toBeIn([401, 403])
        ->and($response->getContent())->not->toContain('Contrato sigiloso');
});
