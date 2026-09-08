<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (fidelidade de design) — controles que a Fase 1 OCULTA
|--------------------------------------------------------------------------
| ROUTES_AND_PAGES distingue dois tratamentos para o que é Fase 2:
|   (a) "desabilitado com badge/tooltip" — ex.: itens da sidebar admin, "Novo modelo";
|   (b) "OCULTO" — literalmente, o controle não é renderizado.
|
| Dois controles do grupo (b) estão sendo renderizados desabilitados:
|   - "Acessar como" na lista do painel interno.
|       §1.5: "Na Fase 1 o botão fica oculto."
|       §2.20: "'Acessar como' oculto na Fase 1."
|   - coluna "WhatsApp" da matriz de Notificações.
|       §1.2 (Configurações — Notificações): "Colunas Fase 1: E-mail e No app
|       (WhatsApp → Fase 2, coluna oculta)."
|
| RECONCILIACAO §5 remete explicitamente a ROUTES_AND_PAGES para decidir entre
| placeholder e controle desabilitado, então ROUTES prevalece sobre o mock.
*/

function reviewFrontFile(string $relative): string
{
    $path = base_path($relative);

    expect(file_exists($path))->toBeTrue("Arquivo esperado não encontrado: {$relative}");

    return (string) file_get_contents($path);
}

it('não renderiza o botão "Acessar como" na lista do painel interno (ROUTES §1.5 e §2.20)', function () {
    $source = reviewFrontFile('resources/js/pages/admin/organizations/index.tsx');

    expect(str_contains($source, 'Acessar como'))->toBeFalse(
        'ROUTES_AND_PAGES §2.20: «"Acessar como" oculto na Fase 1». O botão está renderizado '
            .'(disabled + tooltip "Impersonação chega na Fase 2") em cada linha da tabela.'
    );
});

it('não renderiza a coluna WhatsApp na matriz de notificações (ROUTES §1.2)', function () {
    $source = reviewFrontFile('resources/js/pages/settings/notifications.tsx');

    expect(str_contains($source, 'WhatsApp'))->toBeFalse(
        'ROUTES_AND_PAGES §1.2: «Colunas Fase 1: E-mail e No app (WhatsApp → Fase 2, coluna oculta)». '
            .'A coluna está no cabeçalho e um checkbox desabilitado é renderizado em cada uma das 7 linhas.'
    );
});
