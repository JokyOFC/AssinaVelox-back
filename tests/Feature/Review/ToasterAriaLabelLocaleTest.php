<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (fidelidade de design) — string em inglês na interface
|--------------------------------------------------------------------------
| `resources/js/components/ui/sonner.tsx` monta o <Toaster> do sonner sem
| `containerAriaLabel`. O default da biblioteca é "Notifications", e o sonner
| concatena o rótulo do atalho, produzindo em TODA página do app:
|
|     <section aria-label="Notifications alt+T" ...>
|
| Medido no DOM (painel interno › Clientes, mas vale para qualquer tela do app):
| é o ÚNICO aria-label/title em inglês da casca — todos os outros já estão em
| PT-BR ("Navegação principal", "Alternar menu lateral", "Trilha de navegação",
| "Ajuda", "Ações"). Regra do projeto: identificadores em inglês, interface e
| mensagens em PT-BR.
|
| Este teste é irmão de UserFacingCopyTest ("não mantém textos de acessibilidade
| em inglês nos componentes do kit"), que só enxerga literais escritos no arquivo
| e por isso não pega rótulos que vêm por default da dependência.
*/

it('define o rótulo do live region do Toaster em PT-BR (sonner usa "Notifications" por default)', function () {
    $path = base_path('resources/js/components/ui/sonner.tsx');

    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect(str_contains($source, 'containerAriaLabel'))->toBeTrue(
        'O <Toaster> não passa `containerAriaLabel`; o sonner então anuncia a região como '
            .'"Notifications alt+T" (inglês) em todas as telas autenticadas.'
    );

    expect(preg_match('/containerAriaLabel\s*=\s*["\'][^"\']*[ãçõéí][^"\']*["\']/u', $source))->toBe(
        1,
        'O `containerAriaLabel` precisa ser um texto em português (ex.: "Notificações").'
    );
});
