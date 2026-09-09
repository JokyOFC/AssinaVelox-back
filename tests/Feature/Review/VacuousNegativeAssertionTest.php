<?php

use PHPUnit\Framework\ExpectationFailedException;

/*
|--------------------------------------------------------------------------
| Revisão adversarial — a varredura "nenhuma superfície afirma assinatura digital" não assere nada
|--------------------------------------------------------------------------
| `tests/Feature/EndToEnd/FullLifecycleTest.php:623-625` era a asserção que o relatório de
| integração citava como prova de que, sem certificado, nenhuma das quatro superfícies
| (detalhe, evidências, pública, texto do PDF) diz "assinatura digital" ou "ICP-Brasil":
|
|     expect($lowered)->not->toContain('assinado digitalmente', "'{$where}' afirma …");
|
| `Pest\Expectation::toContain()` é **variádico**: o segundo argumento não é mensagem, é
| outra agulha. Sob `not`, a `OppositeExpectation` captura a falha da primeira agulha ausente
| e passa — e a "mensagem" nunca está no texto. A varredura passava com o texto contendo a
| agulha que ela deveria proibir.
|
| O comportamento do Pest é do vendor e não se corrige aqui; o que se corrige é o USO. Este
| arquivo faz as duas coisas:
|
|  1. fixa o comportamento variádico com strings puras, para que a razão da proibição fique
|     documentada e uma mudança futura do Pest apareça como falha aqui; e
|  2. varre a suíte inteira atrás do padrão `not->toContain($agulha, $mais)`, que é o defeito
|     de verdade — uma expectativa negativa com mais de uma agulha nunca prova o que parece
|     provar. (Este próprio arquivo fica de fora da varredura: ele contém o padrão de
|     propósito, entre aspas, para demonstrá-lo.)
*/

it('fixa o motivo da proibição: a forma variádica não falha com a agulha presente', function () {
    $text = 'este arquivo recebeu uma assinatura digital no perfil pades-b-b';

    // Forma correta (uma agulha): falha, como tem de falhar.
    $strict = null;

    try {
        expect($text)->not->toContain('assinatura digital');
    } catch (ExpectationFailedException $exception) {
        $strict = $exception;
    }

    expect($strict)->toBeInstanceOf(ExpectationFailedException::class);

    // Forma com "mensagem" (na verdade, uma segunda agulha): NÃO falha — é por isso que ela
    // está proibida na suíte. Se um dia o Pest passar a falhar aqui, esta expectativa avisa.
    $withMessage = null;

    try {
        expect($text)->not->toContain('assinatura digital', 'uma segunda agulha qualquer');
    } catch (ExpectationFailedException $exception) {
        $withMessage = $exception;
    }

    expect($withMessage)->toBeNull(
        'o Pest mudou: `not->toContain()` variádico agora falha — reveja a proibição desta varredura.',
    );
});

it('não deixa nenhuma expectativa negativa da suíte usar mais de uma agulha', function () {
    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        // Este arquivo demonstra o padrão de propósito.
        if (str_ends_with($path, 'tests/Feature/Review/VacuousNegativeAssertionTest.php')) {
            continue;
        }

        $source = withoutPhpComments((string) file_get_contents($file->getPathname()));

        foreach (vacuousNegativeCalls($source) as $line) {
            $offenders[] = basename($path).':'.$line;
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders));
});

/**
 * O mesmo código-fonte com os comentários trocados por espaços (as quebras de linha ficam,
 * para que os números de linha não mudem). Um comentário que cita o padrão proibido é
 * documentação, não asserção.
 */
function withoutPhpComments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/**
 * Linhas com `not->toContain(` cujo primeiro nível de argumentos tem vírgula — isto é, mais
 * de uma agulha. O varredor respeita aspas e parênteses aninhados para não confundir uma
 * vírgula dentro de uma string ou de uma chamada aninhada com um segundo argumento, e
 * ignora a vírgula final (estilo do Pint) antes do parêntese de fechamento.
 *
 * @return list<int>
 */
function vacuousNegativeCalls(string $source): array
{
    $needle = 'not->toContain(';
    $lines = [];
    $offset = 0;

    while (($start = strpos($source, $needle, $offset)) !== false) {
        $i = $start + strlen($needle);
        $offset = $i;
        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for (; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }

            if ($char === ')' && $depth === 0) {
                break;
            }

            if ($char === ')' || $char === ']') {
                $depth--;

                continue;
            }

            if ($char === ',' && $depth === 0) {
                $rest = ltrim(substr($source, $i + 1));

                if ($rest !== '' && $rest[0] === ')') {
                    // Vírgula final de lista, não um segundo argumento.
                    continue;
                }

                $lines[] = substr_count(substr($source, 0, $start), "\n") + 1;

                break;
            }
        }
    }

    return $lines;
}
