<?php

use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Phase3/Risk/Support/RiskHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 3, parte 1 (produto) — a restrição por risco não diz ao cliente, NO
| APP, o que fazer
|--------------------------------------------------------------------------
| Roadmap §3.7 ("mensagem clara ao usuário e ao administrador") e docs/fase-3/antifraude.md §6:
| a organização `restricted` precisa saber o motivo e como pedir revisão humana (LGPD art. 20).
|
| Hoje:
| 1. o único aviso dentro do app é o toast de erro ao tentar enviar, e ele traz o caminho CRU,
|    como texto, no meio da frase: "... acesse /revisao-de-seguranca ou escreva para ..."
|    (app/Services/Risk/SendingRestriction.php:43 — `route(..., [], false)`); o toast não tem link
|    e o caminho relativo não serve a quem lê;
| 2. nenhuma tela do app do cliente aponta para a página "Revisão de segurança da conta": não há
|    item de menu, faixa ou aviso para a organização restrita (a página só é alcançável pelo link
|    do e-mail ou digitando a URL). O painel (dashboard) da organização restrita não menciona a
|    restrição nem o caminho da revisão.
*/

beforeEach(function (): void {
    $this->withoutVite();
    riskEnable();
});

it('o aviso de envio bloqueado não traz caminho cru de URL no meio da frase', function () {
    fakeEmailProvider();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    riskSetStatus($organization, 'restricted');

    actingAsMember($owner, $organization);

    $response = $this->post(route('envelopes.send', $envelope))->assertRedirect();
    $message = (string) session('error');

    // Controle: é de fato o aviso do antifraude.
    expect($message)->toContain('suspenso')
        // O defeito: "acesse /revisao-de-seguranca" — um caminho relativo como texto, sem link.
        ->and($message)->not->toMatch('~\s/[a-z0-9-]+(?:/[a-z0-9-]+)*(?=[\s.,]|$)~');
});

it('a organização restrita encontra no app o caminho para pedir a revisão, sem precisar tentar enviar', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    riskSetStatus($organization, 'restricted');

    actingAsMember($owner, $organization);

    $appeal = route('risk.appeal.show', [], false);

    $this->get(route('dashboard'))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($appeal) {
            $props = json_encode($page->toArray()['props'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            expect($props)->toContain($appeal);
        });
});
