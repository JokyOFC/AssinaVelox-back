<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — o guarda de vocabulário (T1) deixa passar afirmações
|--------------------------------------------------------------------------
| tests/Feature/Phase2/VocabularyTest.php é a guarda que "toda feature nova herda". Três furos:
|
| 1. A exceção "negação em inglês (comentário de código)" é `/\b(?:never|not|no)\b/iu`. Em
|    português "no" é preposição ("no celular", "no documento"): QUALQUER afirmação precedida
|    de "no" na mesma oração passa. O mesmo com "sem" que nega OUTRA coisa na mesma oração
|    (a oração só termina em ". ; ! ?" — vírgula e dois-pontos não cortam).
| 2. A lista não tem "identidade confirmada", "prova de vida" nem "reconhecimento facial" —
|    termos que docs/fase-2/identidade.md §1 declara proibidos e que o próprio
|    IdentityCaptureTest procura.
| 3. As pastas varridas não incluem app/Services nem app/Enums, onde moram os textos jurídicos
|    gravados (ConsentText), a nota da captura (CaptureEvidence), os rótulos da consulta de CPF
|    (CpfLookup), o texto do carimbo (StampEvidence) e os rótulos da trilha (AuditEventType —
|    que hoje dizem "Identidade confirmada" para o código por SMS).
|
| O detector é carregado a partir do próprio arquivo do teste, num namespace isolado, para que
| esta prova use exatamente as regras em vigor.
*/

const REVIEW_2B_VOCAB_NS = 'ReviewPhase2BVocabulary';

function review2bVocabularyLoad(): void
{
    if (function_exists(REVIEW_2B_VOCAB_NS.'\\vocabularyViolations')) {
        return;
    }

    $source = (string) file_get_contents(base_path('tests/Feature/Phase2/VocabularyTest.php'));
    $cut = strpos($source, "\ntest(");
    $prefix = substr($source, 0, $cut === false ? strlen($source) : $cut);
    $prefix = (string) preg_replace('/^<\?php\s*/', '', $prefix);
    $prefix = (string) preg_replace('/^require_once .*;$/m', '', $prefix);

    eval('namespace '.REVIEW_2B_VOCAB_NS.'; '.$prefix);
}

/**
 * @return list<string>
 */
function review2bViolations(string $text): array
{
    review2bVocabularyLoad();

    return call_user_func(REVIEW_2B_VOCAB_NS.'\\vocabularyViolations', 'x', $text);
}

it('uma afirmação precedida da preposição "no" passa pelo detector como se fosse negação em inglês', function () {
    // Controle: a mesma afirmação sem "no" antes é pega.
    expect(review2bViolations('Assine pelo celular com assinatura avançada'))->toHaveCount(1);

    // "no celular" casa `\bno\b` e a afirmação é tratada como "comentário de código em inglês".
    expect(review2bViolations('Assine no celular com assinatura avançada'))->toHaveCount(1);
});

it('"sem" que nega outra coisa na mesma oração libera o termo proibido que vem depois', function () {
    // "Sem papel" não nega a assinatura qualificada — só a vírgula/dois-pontos separam.
    expect(review2bViolations('Sem papel e sem filas: assinatura qualificada em minutos'))->toHaveCount(1);
});

it('termos que a própria documentação da onda B proíbe não estão na lista', function (string $claim) {
    expect(review2bViolations($claim))->not->toBe([], 'O detector não pegou: '.$claim);
})->with([
    'identidade confirmada' => ['Identidade confirmada pelo código enviado por SMS'],
    'prova de vida' => ['Selfie com prova de vida'],
    'reconhecimento facial' => ['Captura com reconhecimento facial do participante'],
]);

it('as pastas varridas não cobrem os textos jurídicos e rótulos gerados em PHP', function () {
    review2bVocabularyLoad();

    $scanned = array_keys(constant(REVIEW_2B_VOCAB_NS.'\\VOCAB_SCAN'));

    // ConsentText (declaração e aviso gravados), CaptureEvidence, CpfLookup, StampEvidence…
    expect($scanned)->toContain('app/Services')
        // …e os rótulos da trilha impressos na página de evidências.
        ->and($scanned)->toContain('app/Enums');
});
