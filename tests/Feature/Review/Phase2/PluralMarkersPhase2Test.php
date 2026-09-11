<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda A (texto) — marcadores de plural "(s)" em telas novas
|--------------------------------------------------------------------------
| A Fase 1 já fixou que a cópia não usa "(s)" (SignerCopyAndLabelsTest). As telas novas da
| onda A voltaram a usar:
|   - Relatórios › Uso do plano: "7 documento(s) consumidos da cota no período selecionado"
|     (visto no navegador; resources/js/pages/reports/index.tsx:450);
|   - flash ao excluir etiqueta: "Etiqueta excluída e removida de N documento(s)."
|     (app/Http/Controllers/Tags/TagController.php:102);
|   - flash ao aplicar etiqueta: "… aplicada a N documento(s)."
|     (app/Http/Controllers/Tags/EnvelopeTagController.php:41).
| O projeto tem `plural()` no front e `trans_choice`/ternário no PHP para isso.
*/

dataset('phase2_copy_files', [
    'Relatórios' => 'resources/js/pages/reports/index.tsx',
    'Excluir etiqueta' => 'app/Http/Controllers/Tags/TagController.php',
    'Aplicar etiqueta' => 'app/Http/Controllers/Tags/EnvelopeTagController.php',
]);

it('não usa o marcador "(s)" na cópia', function (string $relative) {
    $path = base_path($relative);

    expect(file_exists($path))->toBeTrue("Arquivo esperado não encontrado: {$relative}");

    preg_match_all('/\p{L}+\(s\)/u', (string) file_get_contents($path), $matches);

    expect($matches[0])->toBe([], "{$relative} usa marcador de plural: ".implode(', ', $matches[0]));
})->with('phase2_copy_files');
