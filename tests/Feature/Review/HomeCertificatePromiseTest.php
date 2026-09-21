<?php

use App\Models\CertificateReference;

/*
|--------------------------------------------------------------------------
| Revisão final (semântica) — a porta de entrada não promete a assinatura A1
|--------------------------------------------------------------------------
| O achado original era da home institucional (`pages/marketing/home.tsx`): um card afirmava,
| sem condicional nenhuma, "O documento concluído é assinado com o certificado A1 da
| AssinaVelox". Na Fase 1, sem `certificate_references` ativo — o padrão de qualquer
| instalação nova — `signature_status = none`, e a própria tela de detalhe diz o oposto:
| "Nenhum certificado da operadora estava ativo na finalização, então o arquivo final não tem
| assinatura criptográfica."
|
| Em 2026-09-21 a home saiu, a pedido do proprietário: `/` leva direto ao login. A porta de
| entrada passou a ser o aside de `layouts/auth-layout.tsx` (hero e selos), e a regra vale
| para ele — arquitetura.md §2: "Sem certificado … a UI diz exatamente isso."
*/

beforeEach(fn () => $this->withoutVite());

it('a porta de entrada não afirma que o documento concluído é assinado com o certificado A1', function () {
    // Estado padrão da Fase 1: nenhum certificado da operadora ativo.
    expect(CertificateReference::query()->where('is_active', true)->exists())->toBeFalse();

    $source = (string) file_get_contents(resource_path('js/layouts/auth-layout.tsx'));

    // Só interessa o texto renderizado; comentários de código são o lugar certo para a nota.
    $rendered = preg_replace('~\{/\*.*?\*/\}~s', '', $source) ?? $source;
    $rendered = preg_replace('~/\*.*?\*/~s', '', $rendered) ?? $rendered;

    expect($rendered)->not->toContain('é assinado com o certificado A1');
});

it('a própria tela de detalhe diz o contrário quando não há certificado — o controle do achado', function () {
    $show = (string) file_get_contents(resource_path('js/pages/envelopes/show.tsx'));

    expect($show)->toContain('Nenhum certificado da operadora estava ativo na finalização');
});
