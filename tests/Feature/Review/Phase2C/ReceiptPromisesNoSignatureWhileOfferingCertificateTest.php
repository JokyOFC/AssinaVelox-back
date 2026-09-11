<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda C (semântica T1) — o comprovante contradiz o cartão do certificado
|--------------------------------------------------------------------------
| Visto no navegador (AV-00007 em `finalizing`, participant_a1 ligado, sem certificado da operadora):
| o comprovante afirma "Ao final, este documento será concluído como aceite eletrônico com
| evidências, sem assinatura criptográfica" e, logo abaixo, o cartão diz "O arquivo está pronto.
| Se quiser, acrescente uma assinatura com o seu certificado A1".
|
| A frase vem de ConsentText::completionNotice() (app/Services/Signing/ConsentText.php:829), que só
| considera o certificado da OPERADORA; SignerPageProps.php:576 a usa para qualquer envelope ainda não
| terminal. Com a flag `participant_a1` e um pedido registrado, a previsão é falsa: o arquivo final
| poderá ter assinatura criptográfica do participante (signature_status `participants_a1`).
*/

use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Phase2/ParticipantA1/Support/ParticipantA1Helpers.php';

beforeEach(function () {
    participantA1Boot($this, operator: false);
    config()->set('inertia.ssr.enabled', false);
    $this->withoutVite();
});

afterEach(function () {
    participantA1Teardown($this);
});

it('não promete "sem assinatura criptográfica" a quem escolheu assinar com o próprio certificado', function () {
    $scenario = participantA1Scenario($this->work);
    $token = $scenario['tokens']['maria@exemplo.test'];

    participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertCreated();

    $response = $this->get(route('sign.show', ['token' => $token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sign/show')->has('receipt.completion_notice'));

    // O cartão, na mesma tela, oferece acrescentar a assinatura criptográfica do participante.
    $state = $this->getJson(route('sign.certificate.show', ['token' => $token]))->assertOk()->json();
    expect($state['stage'])->toBe('ready_to_upload');

    $notice = (string) data_get($response->viewData('page'), 'props.receipt.completion_notice');

    expect($notice)->not->toContain('sem assinatura criptográfica');
});
