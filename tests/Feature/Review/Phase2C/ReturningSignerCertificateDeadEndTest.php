<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda C (produto) — quem volta para enviar o certificado não entra
|--------------------------------------------------------------------------
| O cartão "Assinar também com o seu certificado digital" diz, no estágio `choose`/`awaiting_others`,
| "Volte por este link quando todos os participantes tiverem concluído o aceite para enviar o
| certificado" (participant-certificate-card.tsx:790 e :822). Quem volta horas depois não tem mais a
| janela de download (30 min, SignerDownloadGrants::ttlMinutes) e o cartão responde
| "Para continuar, confirme sua identidade de novo: abra o link do convite neste navegador e informe
| o código recebido" (participant-certificate-card.tsx:511-519) — sem botão nenhum.
|
| Visto no navegador (review-2c-produto.sqlite, AV-00007 em `finalizing`, Elisa já assinou): a tela é
| o comprovante e os únicos controles interativos são dois botões "Copiar". E o servidor recusa o
| pedido de código de quem já assinou: Challenges::assertCanSend lança `not_signable` quando o contexto
| não está ativo (app/Services/Signing/Challenges.php:212). As `otp` props só existem na tela
| `identify` (SignerPageProps.php:136). Resultado: a janela de 72 h anunciada no cartão é inalcançável
| para quem não enviou o certificado nos 30 min seguintes ao próprio aceite.
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

it('quem já aceitou e volta sem a janela de download consegue pedir um novo código para enviar o certificado', function () {
    $scenario = participantA1Scenario($this->work);
    $token = $scenario['tokens']['maria@exemplo.test'];

    // Registrou a escolha logo depois do aceite (com a janela de download aberta).
    participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertCreated();

    // Volta mais tarde, sem a janela: o cartão pede para confirmar a identidade de novo.
    $this->flushSession();

    $state = $this->getJson(route('sign.certificate.show', ['token' => $token]))->assertOk()->json();

    expect($state['stage'])->toBe('ready_to_upload')
        ->and($state['authenticated'])->toBeFalse()
        ->and($state['can_upload'])->toBeFalse();

    // O caminho que o cartão indica ("informe o código recebido") precisa existir.
    $this->from(route('sign.show', ['token' => $token]))
        ->post(route('sign.otp.send', ['token' => $token]))
        ->assertSessionHasNoErrors();
});

it('a tela de quem já aceitou oferece o formulário de código quando o cartão do certificado exige nova confirmação', function () {
    $scenario = participantA1Scenario($this->work);
    $token = $scenario['tokens']['maria@exemplo.test'];

    participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    $this->flushSession();

    $this->get(route('sign.show', ['token' => $token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sign/show')
            ->where('otp', fn ($otp) => $otp !== null));
});
