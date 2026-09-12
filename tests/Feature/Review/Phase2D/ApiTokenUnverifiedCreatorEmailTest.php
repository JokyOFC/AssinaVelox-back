<?php

use App\Models\Envelope;

require_once __DIR__.'/../../Phase2/Api/Support/ApiHelpers.php';

/*
| Revisão adversarial — Fase 2, onda D (lente: segurança da API).
|
| Todo o grupo `app` exige `verified` (routes/web.php: auth + verified + org + org.2fa). Quem
| troca o e-mail volta a "não verificado" (Fortify, ROUTES §… "mudança de e-mail reenvia
| verificação") e fica fora de todas as telas até confirmar o endereço novo.
|
| Defeito: App\Http\Middleware\ApiAuthenticate::resolve() não confere `email_verified_at` do
| criador. O token segue valendo para quem, na interface, não passa do aviso de verificação —
| a API faz mais do que a pessoa pode fazer agora (docs/fase-2/api-v1.md §2.3).
*/

test('criador com e-mail não verificado: barrado na interface E pela API', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    apiEnable($organization);
    $token = apiIssueToken($organization, $owner);

    Envelope::factory()->forOrganization($organization, $owner)->draft()->create(['title' => 'Minuta confidencial']);

    // Troca de e-mail: a conta volta a "não verificada".
    $owner->forceFill(['email' => 'novo-endereco@exemplo.com', 'email_verified_at' => null])->save();

    actingAsMember($owner->fresh(), $organization);
    $this->get(route('envelopes.index'))->assertRedirect(route('verification.notice'));
    auth()->guard()->logout();

    $response = $this->getJson('/api/v1/envelopes', apiHeaders($token));

    expect($response->status())->toBeIn([401, 403])
        ->and($response->getContent())->not->toContain('Minuta confidencial');
});
