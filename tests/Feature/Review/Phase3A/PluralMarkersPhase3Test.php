<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 3, parte 1 (texto) — marcadores de plural "(s)" em telas novas
|--------------------------------------------------------------------------
| A Fase 1 fixou que a cópia não usa "(s)" (SignerCopyAndLabelsTest) e a revisão da Fase 2 repetiu
| a regra (Review/Phase2/PluralMarkersPhase2Test). As telas novas do antifraude voltaram a usar:
|   - Revisão de segurança da conta (cliente, LGPD art. 20) — visto no navegador:
|     "A equipe responde em até 5 dia(s) útil(eis), por e-mail." e "Resposta em até 5 dia(s)
|     útil(eis)." (resources/js/pages/admin/risk-appeal/show.tsx:133 e :155);
|   - fila do antifraude: "1 caso(s) aguardando revisão" (resources/js/pages/admin/risk/index.tsx:150);
|   - caso de risco: "N ponto(s)" (resources/js/pages/admin/risk/show.tsx:184).
| O projeto tem `plural()` em resources/js/lib/format.ts para isso.
*/

dataset('phase3_copy_files', [
    'Revisão de segurança (cliente)' => 'resources/js/pages/admin/risk-appeal/show.tsx',
    'Fila do antifraude' => 'resources/js/pages/admin/risk/index.tsx',
    'Caso de risco' => 'resources/js/pages/admin/risk/show.tsx',
]);

it('não usa marcadores de plural "(s)"/"(eis)" na cópia', function (string $relative) {
    $path = base_path($relative);

    expect(file_exists($path))->toBeTrue("Arquivo esperado não encontrado: {$relative}");

    // "(s)", "(es)", "(eis)" e "(is)" — este último em "1 sinal(is)" na fila (risk/index.tsx:103).
    preg_match_all('/\p{L}+\((?:s|es|eis|is)\)/u', (string) file_get_contents($path), $matches);

    expect($matches[0])->toBe([], "{$relative} usa marcador de plural: ".implode(', ', $matches[0]));
})->with('phase3_copy_files');
