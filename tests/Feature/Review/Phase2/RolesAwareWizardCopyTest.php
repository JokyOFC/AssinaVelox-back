<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda A (semântica) — textos do wizard com papéis ligados
|--------------------------------------------------------------------------
| Com `participant_roles` ligada e UM arquivo, o passo 4 (visto no navegador, com
| testemunha, aprovador e visualizador na lista) ainda mostra o aviso da Fase 1:
|   "Ao enviar, cada signatário recebe um link exclusivo por e-mail. … O aceite eletrônico
|    é vinculado à versão exata do arquivo …"
| O ternário só olha `multi` (vários arquivos), não `showRoles`: aprovador e visualizador
| não são signatários e o visualizador não registra aceite.
| No passo 2, as linhas de testemunha, aprovador e visualizador usam o placeholder
| "Nome do signatário" — o próprio seletor ao lado diz "Visualizador: só acompanha…".
| Fonte: resources/js/components/envelopes/wizard-step-review.tsx:400-402 e
|        resources/js/components/envelopes/wizard-step-recipients.tsx:436.
*/

function rolesCopySource(string $relative): string
{
    $path = base_path($relative);

    expect(file_exists($path))->toBeTrue("Arquivo esperado não encontrado: {$relative}");

    return (string) file_get_contents($path);
}

it('o aviso final do passo 4 não usa o texto "cada signatário" quando há papéis', function () {
    $source = rolesCopySource('resources/js/components/envelopes/wizard-step-review.tsx');
    $position = strpos($source, 'Ao enviar, cada signatário recebe um link exclusivo');

    expect($position)->not->toBeFalse();

    // A condição que escolhe o texto da Fase 1 precisa considerar os papéis (showRoles).
    $condition = substr($source, max(0, $position - 450), 450);

    expect(str_contains($condition, 'showRoles'))->toBeTrue(
        'O texto "Ao enviar, cada signatário recebe… O aceite eletrônico…" é escolhido só por `multi`; '
            .'com testemunha/aprovador/visualizador e um arquivo ele continua aparecendo.'
    );
});

it('o nome de testemunha, aprovador e visualizador não tem o placeholder "Nome do signatário"', function () {
    $source = rolesCopySource('resources/js/components/envelopes/wizard-step-recipients.tsx');

    expect(str_contains($source, 'placeholder="Nome do signatário"'))->toBeFalse(
        'O placeholder fixo "Nome do signatário" aparece em todas as linhas, inclusive visualizador e aprovador.'
    );
});
