<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (onda B, produto) — "Remover logo" apaga sem confirmar
|--------------------------------------------------------------------------
| Em Configurações › Marca e no cartão "Logo da empresa" de Configurações › Geral, o ícone
| de lixeira (logo-upload-field.tsx:131-141, aria-label "Remover logo") chama remove()
| direto (linhas 67-73): router.delete na hora. O servidor apaga o ARQUIVO do disco
| (BrandingManager::removeLogo, `$this->disk()->delete($path)`), e o logo some dos e-mails,
| da página de assinatura, da página de evidências e dos carimbos. Não há desfazer: é
| preciso achar o arquivo original e enviar de novo.
|
| É um botão só de ícone, ghost, colado em "Trocar logo" — fácil de clicar sem querer.
| O restante do produto confirma exclusões com ConfirmDialog (ex.: envelopes/show.tsx,
| "Excluir este rascunho?"; public-forms/edit.tsx, "Revogar o formulário?").
*/

it('"Remover logo" pede confirmação antes de apagar o arquivo', function () {
    $source = (string) file_get_contents(base_path('resources/js/components/branding/logo-upload-field.tsx'));

    $hasConfirmation = str_contains($source, 'ConfirmDialog')
        || str_contains($source, 'AlertDialog');

    expect($hasConfirmation)->toBeTrue(
        'logo-upload-field.tsx: a lixeira "Remover logo" dispara router.delete no onClick, sem '
            .'confirmação, e o servidor apaga o arquivo do logo.'
    );
});
