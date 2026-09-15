<?php

use App\Enums\EnvelopeStatus;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Verification/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Vocabulário obrigatório (roadmap T1; arquitetura §2; e-notariado-e-regulatorio §2.2)
|--------------------------------------------------------------------------
| O produto DESCREVE O MEIO e não afirma nível nem qualidade que não provou. Este teste
| varre UI (resources/js), views Blade (e-mails, evidências, recibos), traduções (lang),
| notificações (app/Notifications), textos legais (docs/juridico) e as respostas da
| verificação pública, e falha se aparecer um termo proibido FORA de um contexto legítimo.
|
| Toda feature nova herda a guarda. Se ele falhar: troque o texto (não amplie a lista de
| exceções sem motivo jurídico/semântico escrito ao lado).
*/

/**
 * Termos proibidos: rótulo => expressão regular (sem diferenciar maiúsculas/acentos
 * óbvios). "ICP-Brasil" é tratado à parte: só é proibido junto de TSA/carimbo do tempo.
 */
const VOCAB_FORBIDDEN = [
    'assinatura avançada' => '/assinatura(?:\s+eletr[ôo]nica)?\s+avan[çc]ada/iu',
    'assinatura qualificada' => '/assinatura(?:\s+eletr[ôo]nica)?\s+qualificada/iu',
    'reconhecimento de firma' => '/reconhecimento\s+de\s+firma/iu',
    'cartório' => '/cart[óo]rio/iu',
    'biometria' => '/biometri[ac]|biom[ée]tric[oa]s?/iu',
    'liveness' => '/liveness/iu',
    'identidade verificada' => '/identidade\s+verificada/iu',
    'validade jurídica garantida' => '/validade\s+jur[íi]dica\s+garantida/iu',
    // docs/fase-2/identidade.md §1: o código prova a posse do canal e a foto é captura
    // simples — nada disso confirma identidade, prova vida ou reconhece rosto.
    'identidade confirmada' => '/identidade\s+confirmada/iu',
    'prova de vida' => '/prova\s+de\s+vida/iu',
    'reconhecimento facial' => '/reconhecimento\s+facial/iu',
];

/** ICP-Brasil só é violação quando associado a TSA/carimbo do tempo na mesma linha (T3). */
const VOCAB_ICP = '/ICP[\s-]?Brasil/iu';

const VOCAB_TSA = '/\bTSA\b|carimbo\s+(?:do|de)\s+tempo|time-?stamp/iu';

/*
| Exceções legítimas — lista explícita. Cada uma é uma expressão aplicada ao TRECHO QUE
| VEM ANTES do termo, dentro da mesma oração (até 160 caracteres, sem ".", ";", "!", "?"
| no meio). Se casar, o termo está sendo NEGADO ou citado como proibição, não afirmado.
|
| As NEGAÇÕES (1 e 2) valem só no trecho depois do último ":" — "Sem papel e sem filas:
| assinatura qualificada" afirma o termo; o "sem" nega outra coisa. A citação da regra (3)
| vale na oração inteira ("Proibido: assinatura avançada").
*/
const VOCAB_ALLOWED_BEFORE = [
    // 1. Negação na mesma oração: "não é assinatura qualificada", "Não coletamos ...
    //    biometria", "não verifica identidade civil, CPF, documentos ou biometria", "não
    //    garante que ... seja aceito por ... cartório", "não é carimbo ICP-Brasil".
    'negação (não/nem/sem/nunca/jamais)' => '/(?:^|[^\p{L}])(?:n[ãa]o|nem|sem|nunca|jamais)(?:[^\p{L}]|$)/iu',
    // 2. Comentários de código que nomeiam o termo proibido como regra ("never", "not").
    //    Sem "no": em português é preposição ("no celular"), não negação.
    'negação em inglês (comentário de código)' => '/\b(?:never|not)\b/iu',
    // 2b. F-I18N: negações de inglês e espanhol que não são palavras do português — a regra
    //     em português (ex.: "biométrico") também vale no texto traduzido.
    'negação em inglês/espanhol (texto traduzido)' => '/\b(?:without|neither|nor|sin|ni|tampoco)\b/iu',
    // 3. Citação da própria regra: "vocabulário proibido", "termos proibidos", "Proibido:".
    'citação da regra (proibido/proibida)' => '/proibid[oa]s?/iu',
];

/** Exceções que só olham o trecho depois do último ":" (negações). */
const VOCAB_NEGATIONS = [
    'negação (não/nem/sem/nunca/jamais)',
    'negação em inglês (comentário de código)',
    'negação em inglês/espanhol (texto traduzido)',
];

/*
| Fase 3 §3.3 (F-I18N, docs/fase-3/multilingue.md §7): a página pública, os e-mails e os textos
| jurídicos de cortesia também existem em inglês e espanhol. Os mesmos termos são proibidos
| nesses idiomas, nos MESMOS contextos permitidos (negação na mesma oração, só depois do último
| ":"; citação da regra), com as palavras de cada idioma. Aqui "no" é negação (em português é
| preposição, por isso fica fora da lista das regras em português).
*/
const VOCAB_FORBIDDEN_TRANSLATED = [
    'advanced (electronic) signature' => '/\badvanced\s+(?:electronic\s+)?signatures?\b/iu',
    'qualified (electronic) signature' => '/\bqualified\s+(?:electronic\s+)?signatures?\b/iu',
    'notarized' => '/\bnotari[sz](?:ed|ation)\b/iu',
    'biometric' => '/\bbiometrics?\b/iu',
    'verified identity' => '/\bverified\s+identity\b|\bidentity\s+(?:is\s+)?verified\b/iu',
    'firma avanzada' => '/\bfirma(?:\s+electr[óo]nica)?\s+avanzada\b/iu',
    'firma cualificada' => '/\bfirma(?:\s+electr[óo]nica)?\s+cualificada\b/iu',
    'notarial' => '/\bnotarial(?:es)?\b/iu',
    // Só a grafia espanhola (com acento): "biometria" e "biométrico" já estão na regra em português.
    'biometría' => '/\bbiometrías?\b/iu',
    'identidad verificada' => '/\bidentidad\s+verificada\b/iu',
];

const VOCAB_ALLOWED_BEFORE_TRANSLATED = [
    // Inclui as negações do português: "notarial" também é palavra do português ("não é ato notarial").
    'negação em inglês/espanhol' => '/(?:^|[^\p{L}])(?:not|no|never|without|neither|nor|sin|nunca|jam[áa]s|ni|tampoco|n[ãa]o|nem|sem)(?:[^\p{L}]|$)/iu',
    'citação da regra (forbidden/prohibido)' => '/\b(?:forbidden|prohibited|prohibid[oa]s?)\b/iu',
];

const VOCAB_NEGATIONS_TRANSLATED = ['negação em inglês/espanhol'];

/*
| Exceções por arquivo — frases legítimas que o critério acima não cobre. Chave: caminho
| relativo; valor: [expressão da linha => motivo]. Hoje vazia de propósito: tudo o que
| existe passa pelo critério da negação.
*/
const VOCAB_FILE_EXCEPTIONS = [];

/** Pastas varridas (caminhos relativos à raiz do projeto) e extensões. */
const VOCAB_SCAN = [
    'resources/js' => ['ts', 'tsx', 'js', 'jsx', 'css'],
    'resources/views' => ['php'],
    'lang' => ['php', 'json'],
    'app/Notifications' => ['php'],
    // Textos gerados em PHP que chegam ao participante, à evidência e ao PDF: ConsentText
    // (declaração e aviso gravados), CaptureEvidence, CpfLookup, StampEvidence, flashes dos
    // controllers e os rótulos dos enums (AuditEventType é impresso na página de evidências).
    'app/Services' => ['php'],
    'app/Enums' => ['php'],
    'app/Http' => ['php'],
    'docs/juridico' => ['md'],
];

/**
 * @return list<string> violações "arquivo:linha: termo — trecho"
 */
function vocabularyViolations(string $source, string $text): array
{
    $violations = [];

    foreach (preg_split('/\R/u', $text) ?: [] as $index => $line) {
        $checks = VOCAB_FORBIDDEN;

        if (preg_match(VOCAB_TSA, $line) === 1) {
            $checks['ICP-Brasil associado a TSA/carimbo do tempo'] = VOCAB_ICP;
        }

        // F-I18N: cada conjunto de termos é conferido com as exceções do seu idioma.
        $sets = [
            [$checks, VOCAB_ALLOWED_BEFORE, VOCAB_NEGATIONS],
            [VOCAB_FORBIDDEN_TRANSLATED, VOCAB_ALLOWED_BEFORE_TRANSLATED, VOCAB_NEGATIONS_TRANSLATED],
        ];

        foreach ($sets as [$terms, $allowed, $negations]) {
            foreach ($terms as $label => $pattern) {
                if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as [$found, $offset]) {
                    if (vocabularyAllowed($source, $line, (int) $offset, $allowed, $negations)) {
                        continue;
                    }

                    $violations[] = sprintf('%s:%d: %s — "%s"', $source, $index + 1, $label, mb_substr(trim($line), 0, 160));
                }
            }
        }
    }

    return $violations;
}

/**
 * @param  array<string, string>  $allowedBefore
 * @param  list<string>  $negations
 */
function vocabularyAllowed(string $source, string $line, int $byteOffset, array $allowedBefore = VOCAB_ALLOWED_BEFORE, array $negations = VOCAB_NEGATIONS): bool
{
    foreach (VOCAB_FILE_EXCEPTIONS[$source] ?? [] as $pattern => $reason) {
        if (preg_match($pattern, $line) === 1) {
            return true;
        }
    }

    $before = substr($line, 0, $byteOffset);
    // Só a oração corrente: corta no último fim de frase.
    $clause = (string) preg_replace('/^.*[.;!?]\s/su', '', $before);
    $clause = mb_substr($clause, -160);
    // Para negações: só o trecho depois do último ":" da oração.
    $narrow = (string) preg_replace('/^.*:/su', '', $clause);

    foreach ($allowedBefore as $label => $pattern) {
        if (preg_match($pattern, in_array($label, $negations, true) ? $narrow : $clause) === 1) {
            return true;
        }
    }

    return false;
}

test('o detector pega afirmações e deixa passar negações', function () {
    expect(vocabularyViolations('x', 'Assinatura qualificada com validade jurídica garantida'))->toHaveCount(2)
        ->and(vocabularyViolations('x', 'Captura com biometria e liveness'))->toHaveCount(2)
        ->and(vocabularyViolations('x', 'Carimbo do tempo ICP-Brasil da nossa TSA'))->toHaveCount(1)
        ->and(vocabularyViolations('x', 'Documento com identidade verificada. Reconhecimento de firma incluso'))->toHaveCount(2)
        ->and(vocabularyViolations('x', 'Não é assinatura qualificada nem reconhecimento de firma.'))->toBe([])
        ->and(vocabularyViolations('x', 'Foto simples, sem biometria e sem liveness.'))->toBe([])
        ->and(vocabularyViolations('x', 'A TSA própria não é carimbo ICP-Brasil.'))->toBe([])
        ->and(vocabularyViolations('x', 'Certificado ICP-Brasil do participante'))->toBe([])
        // A negação de uma frase não protege a frase seguinte.
        ->and(vocabularyViolations('x', 'Não usamos cookies. O aceite tem validade jurídica garantida.'))->toHaveCount(1)
        // Revisão da onda B: "no" é preposição em português, não negação…
        ->and(vocabularyViolations('x', 'Assine no celular com assinatura avançada'))->toHaveCount(1)
        // …e um "sem" antes de ":" nega outra coisa, não o termo que vem depois.
        ->and(vocabularyViolations('x', 'Sem papel e sem filas: assinatura qualificada em minutos'))->toHaveCount(1)
        ->and(vocabularyViolations('x', 'Proibido: assinatura avançada'))->toBe([])
        ->and(vocabularyViolations('x', 'Identidade confirmada pelo código enviado por SMS'))->toHaveCount(1)
        ->and(vocabularyViolations('x', 'Foto simples, sem prova de vida e sem reconhecimento facial.'))->toBe([]);
});

test('o detector pega os equivalentes em inglês e espanhol, nos mesmos contextos', function () {
    expect(vocabularyViolations('x', 'Advanced electronic signature with verified identity'))->toHaveCount(2)
        ->and(vocabularyViolations('x', 'Notarized document with qualified signature'))->toHaveCount(2)
        ->and(vocabularyViolations('x', 'This is not a qualified electronic signature and there is no biometric check.'))->toBe([])
        ->and(vocabularyViolations('x', 'Documento notarial con firma avanzada'))->toHaveCount(2)
        ->and(vocabularyViolations('x', 'Firma cualificada con identidad verificada'))->toHaveCount(2)
        ->and(vocabularyViolations('x', 'Sin biometría y sin identidad verificada.'))->toBe([])
        ->and(vocabularyViolations('x', 'No es una firma avanzada ni cualificada.'))->toBe([])
        ->and(vocabularyViolations('x', 'Forbidden: notarized'))->toBe([])
        // A negação antes de ":" nega outra coisa, também em inglês.
        ->and(vocabularyViolations('x', 'No paper: advanced signature in minutes'))->toHaveCount(1)
        // A negação de uma frase não protege a frase seguinte.
        ->and(vocabularyViolations('x', 'We do not use cookies. Biometric check included.'))->toHaveCount(2);
});

test('UI, views, traduções, notificações e textos legais não usam vocabulário proibido', function () {
    $violations = [];

    foreach (VOCAB_SCAN as $directory => $extensions) {
        $path = base_path($directory);

        if (! is_dir($path)) {
            continue;
        }

        $finder = (new Finder)->files()->in($path)->name(array_map(fn (string $ext): string => '*.'.$ext, $extensions));

        foreach ($finder as $file) {
            $relative = str_replace('\\', '/', $directory.'/'.$file->getRelativePathname());
            $violations = [...$violations, ...vocabularyViolations($relative, $file->getContents())];
        }
    }

    expect($violations)->toBe([], "Vocabulário proibido (T1):\n - ".implode("\n - ", $violations));
});

test('as respostas da verificação pública não usam vocabulário proibido', function () {
    $this->withoutVite();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
    $withCertificate = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    finalizeEnvelope($withCertificate, 'company_a1', null, validationSummary());
    $withoutCertificate = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);
    finalizeEnvelope($withoutCertificate);
    $refused = verifiableEnvelope($organization, $owner, EnvelopeStatus::Refused);

    $responses = [
        'verify.index' => $this->get(route('verify.index')),
        'verify.show (company_a1)' => $this->get(route('verify.show', $withCertificate->verification_code)),
        'verify.show (none)' => $this->get(route('verify.show', $withoutCertificate->verification_code)),
        'verify.show (recusado)' => $this->get(route('verify.show', $refused->verification_code)),
        'verify.show (inexistente)' => $this->get(route('verify.show', 'ABCD-EFGH-JKLM')),
    ];

    $violations = [];

    foreach ($responses as $name => $response) {
        $page = $response->viewData('page');

        // As props do Inertia viajam como JSON com acentos escapados (ó): decodificadas
        // aqui para que "cartório" não escape da busca como "cartório".
        $text = is_array($page)
            ? json_encode($page['props'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : (string) $response->getContent();

        $violations = [...$violations, ...vocabularyViolations($name, (string) $text)];
    }

    expect($violations)->toBe([], "Vocabulário proibido na verificação pública:\n - ".implode("\n - ", $violations));
});
