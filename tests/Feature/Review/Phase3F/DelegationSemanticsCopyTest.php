<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda F (produto/semântica) — cópia da delegação
|--------------------------------------------------------------------------
| T1: "o aceite do delegado é do delegado, nunca 'em nome de' outra pessoa".
|
| 1. O exemplo do campo "Motivo" do diálogo "Delegar a outra pessoa" (página pública) sugere
|    representação — "a pessoa indicada tem poderes para responder por mim" — nos três idiomas.
|    Um leigo lê isso como procuração: o delegado passaria a responder PELO participante, o
|    oposto do que a tela e as evidências dizem ("o aceite que você registrar é seu").
|    resources/js/i18n/messages/{pt_BR,en,es}/refusal.ts, chave `delegation.reason_placeholder`.
|
| 2. A integração I-3F fez o vídeo curto exigido forçar a confirmação de quem enviou
|    (DelegationPolicy::hasStrongerAuthentication), mas o texto do editor de delegação no wizard
|    continua dizendo que só "código por SMS ou WhatsApp, PIN ou fotos exigidas" dependem da
|    confirmação — o remetente que desmarca "Exigir a minha confirmação" com vídeo exigido é
|    surpreendido por um pedido pendente. resources/js/components/envelopes/steps/flow-editor.tsx.
*/

dataset('delegation_placeholder_locales', [
    'PT-BR' => ['pt_BR', '/poderes\s+para\s+responder\s+por\s+mim|responder\s+por\s+mim|em\s+nome\s+de/iu'],
    'inglês' => ['en', '/authority\s+to\s+respond\s+for\s+me|respond\s+for\s+me|on\s+behalf\s+of/iu'],
    'espanhol' => ['es', '/poderes\s+para\s+responder\s+por\s+m[ií]|responder\s+por\s+m[ií]|en\s+nombre\s+de/iu'],
]);

it('o exemplo do motivo da delegação não sugere representação do participante', function (string $locale, string $pattern) {
    $path = base_path("resources/js/i18n/messages/{$locale}/refusal.ts");

    expect(file_exists($path))->toBeTrue("Catálogo não encontrado: {$path}");

    $source = (string) file_get_contents($path);

    preg_match("/'delegation\\.reason_placeholder':\\s*'([^']*)'/u", $source, $match);

    expect($match)->not->toBe([], "Chave delegation.reason_placeholder não encontrada em {$locale}");

    expect(preg_match($pattern, $match[1]))->toBe(
        0,
        "delegation.reason_placeholder ({$locale}) sugere que o delegado responde pelo participante: \"{$match[1]}\"",
    );
})->with('delegation_placeholder_locales');

it('o editor de delegação avisa que o vídeo curto exigido também depende da confirmação de quem enviou', function () {
    $source = (string) file_get_contents(base_path('resources/js/components/envelopes/steps/flow-editor.tsx'));

    // A frase que lista o que força a confirmação, com as quebras de linha do JSX normalizadas.
    $text = (string) preg_replace('/\s+/u', ' ', $source);
    preg_match('/Participante com código por SMS[^.]*\./u', $text, $match);

    expect($match)->not->toBe([], 'A frase sobre autenticação reforçada sumiu do editor de delegação.');

    expect($match[0])->toMatch(
        '/v[íi]deo/iu',
        "O editor diz \"{$match[0]}\", mas DelegationPolicy::hasStrongerAuthentication também conta o vídeo curto exigido.",
    );
});
