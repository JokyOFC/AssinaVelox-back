<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda F (produto/design) — fuso do participante em jargão
|--------------------------------------------------------------------------
| Passo "Signatários" do wizard, com `multilingual` ligada: ao lado de "Idioma dos e-mails e da
| página", o seletor "Fuso horário do participante" lista os identificadores IANA crus do
| navegador — "America/Argentina/Buenos_Aires", "America/New_York", "Etc/GMT+3"… — em inglês,
| com barra e sublinhado, numa interface em PT-BR (DESIGN_SYSTEM: voz em PT-BR, sem jargão).
| Visto no navegador (review-3f-produto, owner@horizonte.demo). O remetente leigo não sabe que
| "America/Sao_Paulo" é o horário de Brasília nem que "Etc/GMT+3" é UTC−3.
|
| resources/js/components/envelopes/recipient-locale-control.tsx: `<option>{zone}</option>`.
*/

it('o seletor de fuso do participante não mostra o identificador IANA cru como rótulo', function () {
    $source = (string) file_get_contents(base_path('resources/js/components/envelopes/recipient-locale-control.tsx'));

    // O rótulo visível da opção é exatamente o identificador (`{zone}`), sem nome legível.
    $rawLabel = preg_match('/<option[^>]*value=\{zone\}[^>]*>\s*\{zone\}\s*<\/option>/u', $source) === 1;

    expect($rawLabel)->toBeFalse(
        'O seletor "Fuso horário do participante" usa o identificador IANA ("America/Argentina/Buenos_Aires") '
        .'como texto da opção. Mostre um nome em PT-BR com o deslocamento (ex.: "Horário de Brasília (UTC−3)").',
    );
});
