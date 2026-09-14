<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 3, parte 1 (acessibilidade) — "Enviar pedido de revisão" fica
| desabilitado sem dizer por quê
|--------------------------------------------------------------------------
| Página "Revisão de segurança da conta" (organização `restricted`, LGPD art. 20 — o canal de
| revisão humana da decisão automatizada). O botão "Enviar pedido de revisão" só habilita com 20
| caracteres ou mais (`message.trim().length < 20`,
| resources/js/pages/admin/risk-appeal/show.tsx:179), mas a tela não diz isso: não há texto de
| ajuda, contador nem `aria-describedby` no campo "Explicação". Visto no navegador (Consultoria Vega
| Demo, restrita): o botão aparece esmaecido e quem digita uma frase curta ("Foi uma campanha.")
| não tem como saber o que falta — num canal que existe justamente para contestar a restrição.
|
| Teste estrutural: se a página exige um mínimo de caracteres, o número precisa aparecer na
| cópia (ou vir do servidor numa prop usada no texto) e o campo precisa apontar para essa ajuda.
*/

it('o mínimo de caracteres do pedido de revisão é informado na tela', function () {
    $source = (string) file_get_contents(base_path('resources/js/pages/admin/risk-appeal/show.tsx'));

    // Pré-condição: a regra existe no front.
    expect(preg_match('/message\.trim\(\)\.length\s*<\s*(\d+)/', $source, $match))->toBe(1);
    $minimum = $match[1];

    // Tudo o que é texto visível (fora de expressões) — a regra precisa estar escrita para quem lê.
    $mentionsMinimum = preg_match('/(?:mínimo|pelo menos|ao menos)[^<{]{0,40}'.$minimum.'|'.$minimum.'\s*caracteres/u', $source) === 1;
    $describedBy = str_contains($source, 'aria-describedby');

    expect($mentionsMinimum && $describedBy)
        ->toBeTrue("O botão só habilita com {$minimum} caracteres, mas a tela não informa o mínimo (nem liga o campo a uma ajuda por aria-describedby).");
});
