<?php

use App\Models\VerificationRecord;
use App\Services\Signing\External\ExternalSignatureNarrative;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 3, parte 1 (produto/semântica) — devolução SEM cadeia verificada
| afirma que o participante assinou "no portal de assinatura do governo"
|--------------------------------------------------------------------------
| Regra (roadmap T1; docs/fase-3/gov-br.md §3; brief integracoes/gov-br-assinatura.md): antes de
| a cadeia ser validada contra as âncoras configuradas, o que se diz é só "assinatura digital de
| terceiro, cadeia não verificada". Sem âncora a plataforma NÃO sabe onde o arquivo foi assinado
| (qualquer PDF assinado por qualquer certificado sobre a versão reservada é aceito).
|
| Hoje, com `participant_external_unverified`, a mesma frase que diz "não se afirma que seja
| assinatura gov.br" afirma, logo antes, que o participante "assinou-a no portal de assinatura do
| governo" — nas evidências e na verificação pública (servidor) e no selo verde (front):
|   - app/Services/Signing/External/ExternalSignatureNarrative.php:183 (só devolução)
|   - app/Services/Signing/External/ExternalSignatureNarrative.php:161 (caso misto)
|   - resources/js/components/verification/seal.ts:63 e :89
|   - também (não coberto por este teste): crypto-signature-list.tsx:116 (introdução da lista) e
|     pages/envelopes/show.tsx:1783-1784 (painel de conclusão do detalhe)
| No navegador (verificação pública do envelope misto do QA) o selo diz, na mesma descrição,
| "um participante devolveu o arquivo assinado no portal de assinatura do governo" e "não se
| afirma que seja assinatura gov.br".
*/

const P3A_UNVERIFIED_CLAIM = '/assin[\p{L}-]*\s+no portal de assinatura do governo/u';

/**
 * @param  array<string, int>  $facts
 */
function p3aNarrative(string $method, array $facts): string
{
    $record = new VerificationRecord;
    $record->forceFill(['signature_profile' => 'PAdES-B-B']);

    $reflection = new ReflectionMethod(ExternalSignatureNarrative::class, $method);

    $args = $method === 'govBrOnlyStatement'
        ? [$record, $facts, null, 'PAdES-B-B']
        : [$record, $facts, null];

    return (string) $reflection->invoke(null, ...$args);
}

function p3aFacts(array $overrides): array
{
    return array_merge(['external' => 0, 'a3' => 0, 'simulated' => 0, 'a1' => 0, 'test' => 0, 'govbr' => 0, 'govbr_trusted' => 0], $overrides);
}

it('evidências/verificação: devolução sem cadeia verificada não afirma que foi assinada no portal do governo', function () {
    $statement = p3aNarrative('govBrOnlyStatement', p3aFacts(['govbr' => 1]));

    // A própria frase admite que não sabe (controle: o texto é o do caso não verificado).
    expect($statement)->toContain('não se afirma que seja assinatura gov.br')
        ->and($statement)->not->toMatch(P3A_UNVERIFIED_CLAIM);
});

it('evidências/verificação: caso misto (componente + devolução sem cadeia) não afirma o portal do governo', function () {
    $statement = p3aNarrative('statement', p3aFacts(['external' => 1, 'simulated' => 1, 'govbr' => 1]));

    expect($statement)->toContain('não se afirma que seja assinatura gov.br')
        ->and($statement)->not->toMatch(P3A_UNVERIFIED_CLAIM);
});

it('selo verde (seal.ts): devolução sem cadeia verificada não afirma o portal do governo', function () {
    $seal = str_replace('\\', '/', base_path('resources/js/components/verification/seal.ts'));
    $script = <<<JS
        const { verificationSeal } = await import('file:///{$seal}');
        const only = verificationSeal('completed', 'participant_external_unverified', {});
        const mixed = verificationSeal('completed', 'participant_external', { portal: true, simulated: true });
        console.log(JSON.stringify({ only: only.description, mixed: mixed.description }));
        JS;

    $process = new Process(['node', '--experimental-strip-types', '--no-warnings', '--input-type=module', '-e', $script], base_path(), null, null, 60);
    $process->run();

    if (! $process->isSuccessful()) {
        $this->markTestSkipped('node indisponível para executar seal.ts: '.mb_substr($process->getErrorOutput(), 0, 300));
    }

    $out = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($out['only'])->toContain('não se afirma que seja assinatura gov.br')
        ->and($out['only'])->not->toMatch(P3A_UNVERIFIED_CLAIM)
        ->and($out['mixed'])->toContain('não se afirma que seja assinatura gov.br')
        ->and($out['mixed'])->not->toMatch(P3A_UNVERIFIED_CLAIM);
});
