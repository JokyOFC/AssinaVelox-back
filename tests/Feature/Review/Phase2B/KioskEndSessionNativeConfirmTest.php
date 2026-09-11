<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (onda B, design) — quiosque usa window.confirm nativo
|--------------------------------------------------------------------------
| "Encerrar sessão presencial" (in-person/kiosk.tsx:233-256) usa window.confirm(), o ÚNICO
| diálogo nativo de todo o front (grep em resources/js). No tablet do balcão isso abre a
| caixa do navegador, com a origem ("127.0.0.1:8134 diz…"), botões no idioma/estilo do
| sistema e fora do design system — exatamente na tela entregue aos participantes.
| Todas as outras confirmações usam ConfirmDialog/AlertDialog (DESIGN_SYSTEM), inclusive o
| "Revogar" do formulário público.
|
| O botão também fica na tela de fila que qualquer participante vê; o diálogo deveria ao
| menos dizer o efeito (todos os que ainda não assinaram perdem a vez neste dispositivo).
*/

it('o quiosque não usa window.confirm para encerrar a sessão presencial', function () {
    $source = (string) file_get_contents(base_path('resources/js/pages/in-person/kiosk.tsx'));

    expect(str_contains($source, 'window.confirm'))->toBeFalse(
        'kiosk.tsx usa window.confirm("Encerrar a sessão presencial neste dispositivo?"), '
            .'diálogo nativo do navegador fora do design system, no dispositivo dos participantes.'
    );
});
