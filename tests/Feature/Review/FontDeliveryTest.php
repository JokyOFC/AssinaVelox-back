<?php

/*
|--------------------------------------------------------------------------
| Revisão de design — a fonte da marca é baixada duas vezes em toda página
|--------------------------------------------------------------------------
| O build emite DOIS `@font-face` por face da Exo 2 — um `.woff2` e um `.woff` —
| com a mesma família, o mesmo peso, o mesmo estilo e o mesmo `unicode-range`.
| Pela cascata do CSS a última regra vence, então o navegador usa sempre o
| `.woff` (o formato maior e mais antigo) e nunca o `.woff2`.
|
| Só que é o `.woff2` que vai no `<link rel="preload" as="font">` do layout: o
| navegador baixa as dez faces em woff2 (≈179 KB medidos no Chrome), descarta
| todas ("preloaded but not used" no console) e em seguida busca os `.woff`.
| Toda página paga isso — inclusive a página pública de assinatura, aberta em
| dados móveis por quem só quer assinar um contrato.
*/

it('declara cada face da fonte da marca uma única vez no CSS publicado', function () {
    $files = glob(public_path('build/assets/fonts-*.css')) ?: [];

    if ($files === []) {
        $this->markTestSkipped('Sem public/build/assets/fonts-*.css (rode npm run build).');
    }

    $css = (string) file_get_contents($files[0]);

    preg_match_all('/@font-face\s*\{(.*?)\}/s', $css, $matches);

    $faces = [];

    foreach ($matches[1] as $body) {
        $key = [];

        foreach (['font-family', 'font-style', 'font-weight', 'unicode-range'] as $property) {
            preg_match('/'.$property.':\s*([^;]+);/', $body, $found);
            $key[] = trim($found[1] ?? '');
        }

        preg_match('/url\("([^"]+)"\)/', $body, $url);

        $faces[implode('|', $key)][] = basename($url[1] ?? '?');
    }

    $duplicated = array_filter($faces, fn (array $urls) => count($urls) > 1);

    $report = [];

    foreach ($duplicated as $key => $urls) {
        $report[] = $key.' → '.implode(', ', $urls);
    }

    expect($duplicated)->toBe(
        [],
        "Faces declaradas mais de uma vez (a última vence e a outra é baixada à toa):\n".implode("\n", $report),
    );
});
