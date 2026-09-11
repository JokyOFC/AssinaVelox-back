<?php

require_once __DIR__.'/../../Phase2/Branding/Support/BrandingHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial (onda B, produto) — pendência interna exibida ao cliente
|--------------------------------------------------------------------------
| Em Configurações › Marca, o bloco "Remetente próprio" mostra "O que falta para ativar:"
| (settings/branding.tsx:266-277) com a lista que vem de BrandingController.php:60-63:
|   - "Documentação da API do serviço de e-mail próprio: registros DNS exigidos (DKIM, SPF,
|      Return-Path), consulta de status do domínio, autenticação, erros e limites."
|   - "Verificação do domínio do remetente nesse serviço (Configurações › Canais, quando
|      disponível)."
|
| É o backlog de integração da OPERADORA (docs/fase-2/onda-b-relatorio.md §8), não uma
| tarefa do cliente: o dono da imobiliária não tem documentação de API nenhuma para
| fornecer. Os termos (DKIM, SPF, Return-Path, "API") são jargão, e a tela manda para
| "Configurações › Canais", que não existe. Visto no navegador (Horizonte, flags ligadas).
| A lista exata do que falta é pendência do proprietário e pertence ao log/relatório; ao
| cliente cabe "ainda não disponível".
*/

it('a tela de Marca não mostra ao cliente o backlog interno de integração do serviço de e-mail', function () {
    brandingOrganization();

    $pending = $this->withoutVite()
        ->get(route('settings.branding'))
        ->assertOk()
        ->viewData('page')['props']['sender']['pending'];

    $text = implode("\n", $pending);

    expect($text)->not->toContain('Documentação da API')
        ->and($text)->not->toContain('DKIM')
        ->and($text)->not->toContain('Configurações › Canais');
});
