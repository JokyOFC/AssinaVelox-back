<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (onda B, produto) — "Enviar link de lote" dispara com um clique
|--------------------------------------------------------------------------
| No cartão do participante (envelopes/show.tsx:1317-1323), o botão "Enviar link de lote"
| faz router.post() direto no onClick (send-batch-link-button.tsx:40-53): sem diálogo, sem
| explicação. Com um clique, sai para o participante um e-mail com um link que abre TODOS
| os documentos pendentes dele nesta conta — inclusive de outros documentos e pastas —, e
| o remetente só descobre quantos entraram pelo flash depois do envio
| (BatchLinkController.php:46-50). Não há como desfazer.
|
| Ações de alcance semelhante no mesmo detalhe (cancelar, excluir) passam por ConfirmDialog
| (show.tsx:1055-1095). Um leigo não sabe o que é "link de lote" nem que ele abrange
| outros documentos: o botão precisa explicar e pedir confirmação antes de enviar.
*/

it('"Enviar link de lote" explica o alcance e pede confirmação antes de enviar', function () {
    $path = base_path('resources/js/components/batch/send-batch-link-button.tsx');
    $source = (string) file_get_contents($path);

    $hasConfirmation = str_contains($source, 'ConfirmDialog')
        || str_contains($source, 'AlertDialog')
        || str_contains($source, '<Dialog');

    expect($hasConfirmation)->toBeTrue(
        'send-batch-link-button.tsx envia o link de lote no onClick, sem diálogo que diga ao '
            .'remetente que o e-mail abre todos os documentos pendentes do participante na conta.'
    );
});
