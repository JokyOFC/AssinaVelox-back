<?php

require_once __DIR__.'/../../Phase3/External/Support/ExternalSigningHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 3, parte 1 (produto) — o cartão oferece "Quero assinar também com
| certificado em token" quando NENHUM componente pode assinar
|--------------------------------------------------------------------------
| Classe B (viabilidade §3.2; docs/fase-3/assinatura-externa-a3.md §8): o NexU está com a produção
| desabilitada (`NexuLocalSigner::PRODUCTION_ENABLED = false`) e o simulador só existe em
| local/teste. Num ambiente em que nenhum dos dois está disponível, o participante ainda assim vê
| o botão "Quero assinar também com certificado em token" (estágio `choose`) e consegue registrar a
| intenção (201). Na hora de assinar, o cartão só diz "Nenhum componente de assinatura está
| disponível nesta plataforma no momento" — e a intenção registrada faz a finalização ESPERAR por
| uma assinatura que não pode acontecer até a janela vencer.
|
| `can_request` (app/Services/Signing/External/ExternalSignatureService.php:171) não considera
| a disponibilidade de componente; o front (external-signing-card.tsx:874) mostra o botão só por
| `can_request`.
*/

beforeEach(function () {
    externalBoot($this);

    // Nenhum componente disponível: simulador desligado; NexU com a produção desabilitada (padrão).
    config()->set('assinavelox.external_signing.components.simulated.enabled', false);
});

afterEach(function () {
    externalTeardown($this);
});

it('não oferece nem registra a intenção de assinar com token quando nenhum componente pode assinar', function () {
    $scenario = externalScenario($this->work);

    $state = externalShow($this, $scenario, 'maria@exemplo.test')->assertOk()->json();

    // Pré-condição: de fato nenhum componente disponível, participante autenticado, envelope aberto.
    expect(collect($state['components'])->where('available', true)->all())->toBe([])
        ->and($state['authenticated'])->toBeTrue()
        ->and($state['stage'])->toBeIn(['choose', 'ready_to_sign']);

    // O defeito: a escolha é oferecida (botão "Quero assinar também…" / "Assinar também…") mesmo
    // sem ter como ser cumprida.
    expect($state['can_request'])->toBeFalse()
        ->and($state['can_prepare'])->toBeFalse();

    $intent = externalIntent($this, $scenario, 'maria@exemplo.test');
    expect($intent->status())->not->toBe(201);
});
