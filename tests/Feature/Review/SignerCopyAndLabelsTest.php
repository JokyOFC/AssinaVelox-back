<?php

use App\Enums\AuthMethod;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Revisão de design — cópia e rótulos das telas de preparo e de assinatura
|--------------------------------------------------------------------------
| Três desvios de cópia PT-BR e um de acessibilidade, todos em telas que o
| signatário ou o preparador vê:
|
| 1. O botão de desfazer traço dos dois quadros de assinatura chama-se
|    "Refazer" — em português "refazer" é o oposto de "desfazer", e o handler
|    (`data.pop()`) desfaz.
| 2. Marcadores de plural "(s)" sobraram em duas frases da página pública,
|    embora o projeto tenha um helper `plural()` (lib/format) usado ao lado.
| 3. `AuthMethod::email_otp` tem dois nomes na interface: "Código por e-mail"
|    (enum PHP, chip do wizard, comprovante público) e "Token e-mail"
|    (lib/labels.ts, detalhe do documento, Assinaturas, Configurações). ROUTES
|    §6.6 e DESIGN §6.5 fixam um único rótulo.
| 4. `FieldLayer` monta o `aria-label` das caixas com as instruções de edição
|    ("Setas movem, Shift com setas redimensiona, Delete remove.") mesmo em
|    `readOnly`, onde as caixas são `role="img"` — o leitor de tela oferece,
|    no detalhe do documento, atalhos que não existem ali.
*/

function reviewSource(string $relative): string
{
    return (string) file_get_contents(resource_path('js/'.$relative));
}

it('não chama de "Refazer" o botão que desfaz o último traço da assinatura', function () {
    $source = reviewSource('components/signature/signature-pad-canvas.tsx');

    // Rótulo do botão ligado a `onClick={undo}` (o handler faz `data.pop()`).
    preg_match('/onClick=\{undo\}\s*>(.*?)<\/Button>/s', $source, $matches);
    $label = trim(preg_replace('/<[^>]*>/', ' ', $matches[1] ?? '') ?? '');

    expect($label)->not->toBe('', 'Botão de desfazer não encontrado; revise este teste.');
    expect($label)->toBe('Desfazer');
});

it('não deixa marcadores de plural "(s)" na cópia da página pública de assinatura', function () {
    $finder = Finder::create()
        ->files()
        ->in([resource_path('js/pages/sign'), resource_path('js/components/sign')])
        ->name('*.tsx');

    $offenders = [];

    foreach ($finder as $file) {
        $code = (string) $file->getContents();
        $code = preg_replace('~/\*.*?\*/~s', '', $code) ?? $code;

        if (preg_match_all('/\w+\(s\)/u', $code, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $offset]) {
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $offenders[] = $file->getRelativePathname().':'.$line.' → "'.$text.'"';
            }
        }
    }

    expect($offenders)->toBe([], "Plural improvisado em texto de interface:\n".implode("\n", $offenders));
});

it('usa um único rótulo PT-BR para AuthMethod.email_otp no PHP e no TypeScript (ROUTES §6.6)', function () {
    $labels = reviewSource('lib/labels.ts');

    preg_match('/authMethodLabels[^{]*\{\s*email_otp:\s*\'([^\']+)\'/', $labels, $matches);

    expect($matches[1] ?? null)->not->toBeNull('authMethodLabels.email_otp não encontrado em lib/labels.ts');
    expect(AuthMethod::EmailOtp->label())->toBe($matches[1]);
});

it('não anuncia atalhos de edição nas caixas de campo somente leitura (FieldLayer)', function () {
    $source = reviewSource('components/envelopes/field-layer.tsx');

    preg_match('/ariaLabel=\{(.+?)\}\n/s', $source, $matches);
    $expression = $matches[1] ?? '';

    expect(str_contains($expression, 'Setas movem'))
        ->toBeTrue('A expressão do aria-label mudou; revise este teste.');

    // Em `readOnly` as caixas são `role="img"` e nenhum atalho funciona: a
    // instrução precisa depender do modo.
    expect(str_contains($expression, 'readOnly'))
        ->toBeTrue('aria-label anuncia atalhos de edição também no modo somente leitura: '.$expression);
});
