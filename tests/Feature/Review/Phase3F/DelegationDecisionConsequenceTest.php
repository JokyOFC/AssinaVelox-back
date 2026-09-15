<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda F (produto) — "Confirmar delegação" sem dizer o que acontece
|--------------------------------------------------------------------------
| Detalhe do documento, painel "Delegações" (resources/js/components/envelopes/steps/
| flow-panel.tsx, DelegationRow). Quem enviou vê "Ana → Carla (carla@…)", o motivo escrito pela
| participante e dois botões: "Confirmar delegação" e "Recusar".
|
| "Confirmar delegação" dispara o POST na hora: não há diálogo nem texto dizendo as
| consequências — o link da participante original deixa de valer, a pessoa indicada recebe
| convite e código próprios, herda etapa, campos e a exigência de vídeo, e registra o PRÓPRIO
| aceite (nunca "em nome de"). A página pública explica tudo isso a quem pede (dialog_intro,
| dialog_immediate); quem decide, não lê nada. A recusa, ao contrário, abre um segundo passo com
| observação. Confirmar é irreversível pela interface (não existe "desfazer delegação").
|
| O teste lê a fonte: a confirmação precisa de um AlertDialog (ou texto de consequência junto do
| botão) antes do POST `envelopes.delegations.approve`.
*/

it('confirmar a delegação explica a consequência antes de valer', function () {
    $source = (string) file_get_contents(base_path('resources/js/components/envelopes/steps/flow-panel.tsx'));

    $start = strpos($source, 'function DelegationRow');
    expect($start)->not->toBeFalse('DelegationRow não encontrado.');

    $row = substr($source, (int) $start);

    $hasDialog = str_contains($row, 'AlertDialog');
    $explains = preg_match('/deixa\s+de\s+valer|link\s+de\s+\{?[^}]*\}?\s*deixa|pr[óo]prio\s+aceite/iu', $row) === 1;

    expect($hasDialog || $explains)->toBeTrue(
        '"Confirmar delegação" envia o POST sem confirmação e sem dizer que o link do participante original '
        .'deixa de valer e que a pessoa indicada registra o próprio aceite.',
    );
});
