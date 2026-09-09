<?php

use App\Models\CertificateReference;

/*
|--------------------------------------------------------------------------
| Revisão final (semântica) — a home promete a assinatura A1 como fato consumado
|--------------------------------------------------------------------------
| `LegalValidityClaimTest` já tirou "Validade jurídica" e "Certificado A1 da operadora"
| da FAIXA DE SELOS de `pages/marketing/home.tsx`, e o comentário que ficou no lugar
| (home.tsx:81-84) enuncia a regra:
|
|   "nenhum selo aqui pode prometer aceitação jurídica universal nem assinatura com
|    certificado: … a segunda depende de haver certificado configurado."
|
| Trinta linhas ACIMA desse comentário, o array FEATURES — os três cards que a mesma
| página renderiza logo abaixo do hero — faz exatamente a promessa proibida, sem
| condicional nenhuma (home.tsx:18-22):
|
|   title: 'Assinatura criptográfica da operadora'
|   text:  'O documento concluído é assinado com o certificado A1 da AssinaVelox
|           e pode ser verificado publicamente.'
|
| "O documento concluído **é** assinado" é afirmação sobre todo documento concluído. Na
| Fase 1, sem `certificate_references` ativo — o estado desta instalação e o padrão de
| qualquer instalação nova — `signature_status = none` e o próprio produto diz o oposto
| na tela de detalhe: "Nenhum certificado da operadora estava ativo na finalização, então
| o arquivo final não tem assinatura criptográfica."
|
| arquitetura.md §2: "Sem certificado … a UI diz exatamente isso." A porta de entrada do
| produto — a página que um cliente lê ANTES de assinar contrato — diz o contrário.
*/

beforeEach(fn () => $this->withoutVite());

it('a home não afirma que o documento concluído é assinado com o certificado A1', function () {
    // Estado padrão da Fase 1: nenhum certificado da operadora ativo.
    expect(CertificateReference::query()->where('is_active', true)->exists())->toBeFalse();

    $source = (string) file_get_contents(resource_path('js/pages/marketing/home.tsx'));

    // Só interessa o texto renderizado; comentários de código são o lugar certo para a nota.
    $rendered = preg_replace('~\{/\*.*?\*/\}~s', '', $source) ?? $source;
    $rendered = preg_replace('~/\*.*?\*/~s', '', $rendered) ?? $rendered;

    expect($rendered)->not->toContain('é assinado com o certificado A1');
});

it('a própria tela de detalhe diz o contrário quando não há certificado — o controle do achado', function () {
    $show = (string) file_get_contents(resource_path('js/pages/envelopes/show.tsx'));

    expect($show)->toContain('Nenhum certificado da operadora estava ativo na finalização');
});
