<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (fidelidade de design) — breadcrumb da topbar
|--------------------------------------------------------------------------
| Achado: em viewports ≤ 768 px todos os itens do breadcrumb eram flex-children
| encolhíveis com a mesma prioridade (`min-w-0` + `truncate` em todos), então
| faltando espaço TODOS encolhiam proporcionalmente — medido no DOM a 768 px:
| 7 px / 3 px / 3 px / 13 px por item, com os separadores (14 px, `shrink-0`)
| ocupando mais que os textos ("I › I › I › C…").
|
| DESIGN_SYSTEM §3.2 define o item atual como `font-semibold #0b1f42` "com ellipsis
| quando longo" — ou seja, é ELE que deve receber o espaço e truncar; §3.4 prevê no
| mobile "breadcrumb truncado", não breadcrumb apagado.
|
| Como não há como medir layout a partir do PHP, o teste fixa o contrato de classes:
| o item atual é o único flexível (`flex-1 min-w-0`) e os ancestrais não disputam
| largura (`shrink-0`, ocultos abaixo de `lg`, como já se fazia abaixo de `sm`).
*/

it('dá prioridade de largura ao item atual do breadcrumb (DESIGN §3.2 e §3.4)', function () {
    $source = (string) file_get_contents(base_path('resources/js/components/app-topbar.tsx'));

    // Item atual: único item que ganha o espaço restante e trunca.
    expect($source)->toContain("? 'min-w-0 flex-1'");

    // Ancestrais: não encolhem junto e saem de cena abaixo de lg.
    expect($source)->toContain("'hidden max-w-[180px] shrink-0 lg:inline-flex'");

    // Separadores acompanham a visibilidade dos ancestrais (nada de "› › ›" sem texto).
    expect($source)->toContain('text-border-dashed hidden shrink-0 lg:block');

    // Nenhum item ancestral pode voltar a ser `min-w-0` sem `shrink-0`.
    expect($source)->not->toContain("'hidden min-w-0 sm:inline-flex'");
});
