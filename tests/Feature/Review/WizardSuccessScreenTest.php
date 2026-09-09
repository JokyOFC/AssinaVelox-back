<?php

use App\Enums\SigningOrder;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — tela "Enviado para assinatura" (DESIGN §6.5 / ROUTES §2.6)
|--------------------------------------------------------------------------
| O wizard termina numa tela de sucesso: "Sucesso → redirect envelopes.show?sent=1
| que renderiza a tela 'Enviado para assinatura' (texto adapta a routing_mode)"
| (ROUTES §2.6). `pages/envelopes/show.tsx` implementa o banner e o lê da prop
| `sent`.
|
| Os dois lados, porém, falam línguas diferentes: `EnvelopeSendController` grava
| `sent` no FLASH DA SESSÃO (`->with('sent', true)`) e `EnvelopeController@show`
| lê `sent` da ENTRADA DA REQUISIÇÃO (`$request->validate([..., 'sent' => ...])`,
| depois `filter_var($validated['sent'] ?? false, ...)`). Flash de sessão não é
| input: a prop chega sempre `false` e a tela de sucesso desenhada no mock nunca
| aparece para quem envia pelo wizard.
*/

it('entrega sent = true ao detalhe logo depois do envio pelo wizard (DESIGN §6.5 "Sucesso")', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    fakeEmailProvider();

    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test'],
    ], SigningOrder::Sequential);

    actingAsMember($owner, $organization);

    // Exatamente o que o navegador faz: POST do wizard e, em seguida, GET do destino.
    $props = $this->followingRedirects()
        ->post(route('envelopes.send', $envelope))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['sent'])->toBeTrue();
});
