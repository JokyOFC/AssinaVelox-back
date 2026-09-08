<?php

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Revisão de design — cópia visível ao usuário
|--------------------------------------------------------------------------
| 1) Nomes internos de iteração ("Wave B", "Wave C") não podem aparecer em texto
|    de interface, flash ou tooltip: o usuário final só conhece "Fase 2"
|    (ROUTES §1.6 / RECONCILIACAO §5). Comentários de código são ignorados.
| 2) Componentes do kit (resources/js/components/ui) não podem manter textos
|    de acessibilidade em inglês ("Loading", "Close", "More", "Toggle sidebar"):
|    leitores de tela os anunciam em toda a interface (convenção PT-BR).
*/

function reviewStripComments(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', '', $source) ?? $source;

    return preg_replace('~(^|\s)//[^\n]*~', '$1', $source) ?? $source;
}

it('não expõe nomes internos de iteração (Wave A/B/C) em textos de interface do front', function () {
    $finder = Finder::create()
        ->files()
        ->in([resource_path('js/pages'), resource_path('js/components'), resource_path('js/layouts')])
        ->name('*.tsx');

    $offenders = [];

    foreach ($finder as $file) {
        $code = reviewStripComments($file->getContents());

        if (preg_match_all('/Wave [ABC]\b/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $offenders[] = $file->getRelativePathname().':'.$line.' → "'.$text.'"';
            }
        }
    }

    expect($offenders)->toBe([], "Texto de UI com jargão interno:\n".implode("\n", $offenders));
});

it('não expõe nomes internos de iteração (Wave A/B/C) em mensagens flash dos controllers', function () {
    $finder = Finder::create()->files()->in(app_path('Http'))->name('*.php');

    $offenders = [];

    foreach ($finder as $file) {
        $code = reviewStripComments($file->getContents());

        // Só strings literais (mensagens), não identificadores.
        if (preg_match_all("/'[^']*Wave [ABC][^']*'/", $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $offenders[] = $file->getRelativePathname().':'.$line.' → '.$text;
            }
        }
    }

    expect($offenders)->toBe([], "Mensagem ao usuário com jargão interno:\n".implode("\n", $offenders));
});

it('não mantém textos de acessibilidade em inglês nos componentes do kit (resources/js/components/ui)', function () {
    $finder = Finder::create()->files()->in(resource_path('js/components/ui'))->name('*.tsx');

    $patterns = [
        '/aria-label="Loading"/',
        '/>\s*Close\s*</',
        '/>\s*More\s*</',
        '/Toggle sidebar/',
        '/<SheetTitle>Sidebar<\/SheetTitle>/',
    ];

    $offenders = [];

    foreach ($finder as $file) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $file->getContents(), $m)) {
                $offenders[] = $file->getRelativePathname().' → '.trim($m[0]);
            }
        }
    }

    expect($offenders)->toBe([], "Texto em inglês para leitores de tela:\n".implode("\n", $offenders));
});
