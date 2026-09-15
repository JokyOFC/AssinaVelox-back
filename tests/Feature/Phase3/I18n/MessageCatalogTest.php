<?php

use App\Support\Locale\SignerLocale;
use App\Support\Locale\SignerMessageCatalog;

require_once __DIR__.'/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — catálogo de mensagens do fluxo público (docs/fase-3/multilingue.md §5)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => SignerMessageCatalog::flush());

it('não toca em nada no idioma de referência', function () {
    $message = 'Código inválido ou expirado (2 tentativas restantes).';

    expect(SignerMessageCatalog::translate($message, SignerLocale::PtBr))->toBe($message);
});

it('traduz textos exatos e modelos com partes variáveis', function () {
    expect(SignerMessageCatalog::translate('Aceite registrado.', SignerLocale::En))->toBe('Acceptance recorded.')
        ->and(SignerMessageCatalog::translate('Aceite registrado.', SignerLocale::Es))->toBe('Aceptación registrada.')
        ->and(SignerMessageCatalog::translate('Código inválido ou expirado (2 tentativas restantes).', SignerLocale::En))
        ->toBe('Invalid or expired code (2 attempts left).')
        ->and(SignerMessageCatalog::translate('Código inválido ou expirado (1 tentativa restantes).', SignerLocale::Es))
        ->toBe('Código inválido o expirado (queda 1 intento).')
        ->and(SignerMessageCatalog::translate('Aguarde 42s para reenviar o código.', SignerLocale::En))
        ->toBe('Wait 42s to resend the code.');
});

it('devolve o valor capturado como texto, sem interpretar', function () {
    // Rótulo de campo escrito pelo remetente: dado não confiável (T6). Nada é avaliado.
    $label = '{{7*7}} <script>alert(1)</script> :title $(rm -rf)';
    $translated = SignerMessageCatalog::translate(sprintf('Preencha o campo "%s" para continuar.', $label), SignerLocale::En);

    expect($translated)->toBe(sprintf('Fill in the field "%s" to continue.', $label));
});

it('traduz os rótulos conhecidos de uma lista e mantém o resto', function () {
    expect(SignerMessageCatalog::translate('Antes de concluir, envie: Foto do rosto, Vídeo curto.', SignerLocale::En))
        ->toBe('Before finishing, send: Face photo, Short video.');
});

it('deixa em português uma mensagem que o catálogo não conhece', function () {
    expect(SignerMessageCatalog::translate('Mensagem nova de um serviço.', SignerLocale::En))->toBe('Mensagem nova de um serviço.');
});

it('compila todos os modelos do catálogo e cada um casa com a própria mensagem', function () {
    foreach ([SignerLocale::En, SignerLocale::Es] as $locale) {
        foreach (SignerMessageCatalog::entries($locale) as $source => $target) {
            // Uma mensagem de exemplo, com cada `{nome}` preenchido, precisa cair na própria
            // entrada — e não numa vizinha mais curta ou mais genérica.
            $example = (string) preg_replace('/\{[a-z_]+\}/', 'X9', $source);
            $expected = (string) preg_replace('/\{[a-z_]+\}/', 'X9', $target);

            expect(SignerMessageCatalog::translate($example, $locale))->toBe($expected, "[{$locale->value}] {$source}");
        }
    }
});

it('só aceita idiomas da lista fechada', function () {
    expect(SignerLocale::tryFromInput('en'))->toBe(SignerLocale::En)
        ->and(SignerLocale::tryFromInput('pt_BR'))->toBe(SignerLocale::PtBr)
        ->and(SignerLocale::tryFromInput('../../../etc/passwd'))->toBeNull()
        ->and(SignerLocale::tryFromInput('fr'))->toBeNull()
        ->and(SignerLocale::tryFromInput('EN'))->toBeNull()
        ->and(SignerLocale::tryFromInput(['en']))->toBeNull()
        ->and(SignerLocale::tryFromInput(null))->toBeNull();
});
