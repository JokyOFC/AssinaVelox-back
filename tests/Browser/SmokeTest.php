<?php

// Verificação mínima de que o ambiente de testes de navegador funciona.
it('abre a página inicial no navegador', function () {
    $page = visit('/');

    $page->assertNoJavascriptErrors();
});
