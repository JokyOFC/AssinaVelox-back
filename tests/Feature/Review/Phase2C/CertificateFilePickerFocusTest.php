<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda C (acessibilidade) — seletor do certificado sem foco visível
|--------------------------------------------------------------------------
| resources/js/components/certificates/participant-certificate-card.tsx:581-613: o `<input type="file">`
| é `sr-only` e o que se vê é um `<label htmlFor>` IRMÃO dele, estilizado com
| `focus-within:border-primary`. `:focus-within` só casa quando o elemento focado está DENTRO do label;
| como o input é irmão, navegar com Tab até o seletor não mostra indicador nenhum (WCAG 2.4.7). É o
| primeiro controle do envio do certificado na página pública, em desktop e em 375 px.
|
| Correção esperada: pôr o input dentro do label, ou marcar o input com `peer` e o label com
| `peer-focus-visible:`.
*/

it('o seletor do arquivo do certificado mostra foco visível ao navegar pelo teclado', function () {
    $source = (string) file_get_contents(base_path('resources/js/components/certificates/participant-certificate-card.tsx'));

    // O bloco do seletor: do input de arquivo até o fim do label que o representa.
    $start = strpos($source, 'type="file"');
    expect($start)->not->toBeFalse();

    $inputStart = strrpos(substr($source, 0, $start), '<input');
    $labelEnd = strpos($source, '</label>', $start);
    $block = substr($source, (int) $inputStart, (int) $labelEnd - (int) $inputStart);

    $inputTag = substr($block, 0, (int) strpos($block, '/>'));
    $inputIsPeer = (bool) preg_match('/className="[^"]*\bpeer\b/', $inputTag);
    $labelUsesPeerFocus = str_contains($block, 'peer-focus-visible:') || str_contains($block, 'peer-focus:');
    $inputInsideLabel = (bool) preg_match('/<label[^>]*>[\s\S]*type="file"/', substr($source, max(0, (int) $inputStart - 600), 1200))
        && strpos($source, '<label', max(0, (int) $inputStart - 600)) < $inputStart
        && strpos($source, '</label>', max(0, (int) $inputStart - 600)) > $start;

    expect(($inputIsPeer && $labelUsesPeerFocus) || $inputInsideLabel)
        ->toBeTrue('O label visível usa focus-within, mas o input de arquivo é irmão dele: o foco do teclado fica invisível.');
});
