<?php

use Illuminate\Contracts\Translation\Loader;

require_once __DIR__.'/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — nenhuma chave faltando (docs/fase-3/multilingue.md §9)
|--------------------------------------------------------------------------
| Compara, entre pt_BR (referência), en e es: os arquivos de tradução PHP da área
| (signer_legal, signer_ui, signer_mail), o catálogo de mensagens (en × es), os JSON e os
| dicionários do front. Chave faltando, sobrando ou com marcador diferente falha aqui — e o
| `tsc` pega o mesmo no front (`satisfies`).
*/

const I18N_PHP_FILES = ['signer_legal', 'signer_ui', 'signer_mail'];

const I18N_LOCALES = ['pt_BR', 'en', 'es'];

it('tem as mesmas chaves e os mesmos marcadores nos arquivos PHP dos três idiomas', function (string $file) {
    /** @var Loader $loader */
    $loader = app('translator')->getLoader();
    $reference = i18nFlattenKeys($loader->load('pt_BR', $file));

    expect($reference)->not->toBeEmpty();

    foreach (['en', 'es'] as $locale) {
        $lines = i18nFlattenKeys($loader->load($locale, $file));

        expect(array_keys($lines))->toEqualCanonicalizing(array_keys($reference), "{$locale}/{$file}.php: chaves diferentes da referência");

        foreach ($reference as $key => $text) {
            expect(i18nPlaceholders($lines[$key]))->toBe(i18nPlaceholders($text), "{$locale}/{$file}.php [{$key}]: marcadores diferentes");
        }
    }
})->with(I18N_PHP_FILES);

it('tem o mesmo catálogo de mensagens em inglês e em espanhol, com os mesmos marcadores', function () {
    /** @var Loader $loader */
    $loader = app('translator')->getLoader();
    $en = $loader->load('en', 'signer_messages');
    $es = $loader->load('es', 'signer_messages');

    expect($en)->not->toBeEmpty()
        ->and(array_keys($es))->toEqualCanonicalizing(array_keys($en));

    foreach ($en as $source => $target) {
        $expected = i18nPlaceholders($source, 'brace');

        expect(i18nPlaceholders($target, 'brace'))->toBe($expected, "en [{$source}]")
            ->and(i18nPlaceholders($es[$source], 'brace'))->toBe($expected, "es [{$source}]");
    }
});

it('traduz em espanhol todo texto que o JSON em inglês cobre', function () {
    $en = json_decode((string) file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
    $es = json_decode((string) file_get_contents(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_diff(array_keys($en), array_keys($es)))->toBe([]);
});

it('tem os mesmos arquivos, chaves e marcadores nos dicionários do front', function () {
    $base = resource_path('js/i18n/messages');
    $files = collect(glob($base.'/pt_BR/*.ts'))
        ->map(fn (string $path): string => basename($path))
        ->reject(fn (string $name): bool => $name === 'index.ts')
        ->values();

    expect($files)->not->toBeEmpty();

    foreach (['en', 'es'] as $locale) {
        $theirs = collect(glob("{$base}/{$locale}/*.ts"))->map(fn (string $path): string => basename($path))->reject(fn (string $name): bool => $name === 'index.ts')->values();

        expect($theirs->all())->toEqualCanonicalizing($files->all(), "{$locale}: arquivos diferentes");
    }

    $total = 0;

    foreach ($files as $file) {
        $reference = i18nFrontDictionary("{$base}/pt_BR/{$file}");
        $total += count($reference);

        expect($reference)->not->toBeEmpty("pt_BR/{$file} sem chaves lidas");

        foreach (['en', 'es'] as $locale) {
            $dictionary = i18nFrontDictionary("{$base}/{$locale}/{$file}");

            expect(array_keys($dictionary))->toEqualCanonicalizing(array_keys($reference), "{$locale}/{$file}: chaves diferentes");

            foreach ($reference as $key => $text) {
                expect(i18nPlaceholders($dictionary[$key], 'brace'))->toBe(i18nPlaceholders($text, 'brace'), "{$locale}/{$file} [{$key}]");
            }
        }
    }

    // Guarda contra um leitor que não leu nada (formato do arquivo mudou).
    expect($total)->toBeGreaterThan(300);
});

it('não deixa texto traduzido igual ao português onde a tradução é obrigatória', function () {
    // Frases longas idênticas ao PT-BR em en/es indicariam uma chave copiada sem traduzir.
    // Exceções: textos que em espanhol se escrevem exatamente igual ao português.
    $identicalByDesign = [
        'es:document.stamp', // "{code} · pág. {page}/{pages}"
        'es:otp.valid_for', // "Código válido por {minutes} min"
        'es:refusal.min', // "Mínimo de {min} caracteres."
        'es:sign.refused.reason', // "Motivo informado: “{reason}”"
    ];
    $base = resource_path('js/i18n/messages');
    $copied = [];

    foreach (glob($base.'/pt_BR/*.ts') as $path) {
        if (basename($path) === 'index.ts') {
            continue;
        }

        $reference = i18nFrontDictionary($path);

        foreach (['en', 'es'] as $locale) {
            $dictionary = i18nFrontDictionary(str_replace('/pt_BR/', "/{$locale}/", $path));

            foreach ($reference as $key => $text) {
                if (mb_strlen($text) >= 25 && ($dictionary[$key] ?? null) === $text && ! in_array("{$locale}:{$key}", $identicalByDesign, true)) {
                    $copied[] = "{$locale}:{$key}";
                }
            }
        }
    }

    expect($copied)->toBe([]);
});
