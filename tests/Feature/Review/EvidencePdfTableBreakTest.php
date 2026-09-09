<?php

/*
|--------------------------------------------------------------------------
| Revisão de design — tabelas do PDF de evidências quebram sem cabeçalho
|--------------------------------------------------------------------------
| O relatório de evidências é anexado ao arquivo final e é o que um terceiro
| lê no papel. Suas três tabelas longas — "2. Participantes", "3. Linha do tempo"
| e a de resumos da seção 4 — atravessam páginas em qualquer envelope com mais de
| um punhado de participantes ou eventos.
|
| A folha de estilo de `resources/views/evidence/page.blade.php` não declara
| `thead { display: table-header-group; }`, que é como o DOMPDF repete a linha de
| cabeçalho nas páginas seguintes, nem protege a quebra dentro das linhas.
| Consequência: as colunas continuam na página seguinte sem dizer o que são.
|
| Verificado no PDF real gerado nesta revisão (AV-00006, 3 páginas): a página 2
| termina com a linha de cabeçalho "Resumo | Do que foi calculado" logo acima do
| rodapé, e todos os resumos — Original, Enviado, Consolidado, Final — começam na
| página 3, órfãos do próprio cabeçalho. Com dois participantes a tabela 2 coube
| numa página; com seis, as linhas seguintes ficariam igualmente sem cabeçalho.
*/

it('repete o cabeçalho das tabelas do relatório nas quebras de página', function () {
    $blade = (string) file_get_contents(resource_path('views/evidence/page.blade.php'));

    expect($blade)->toContain('table-header-group');
});

it('evita quebrar uma linha de participante ao meio', function () {
    $blade = (string) file_get_contents(resource_path('views/evidence/page.blade.php'));

    expect($blade)->toContain('page-break-inside');
});
