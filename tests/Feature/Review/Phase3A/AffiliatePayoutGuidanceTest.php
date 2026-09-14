<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 3, parte 1 (produto) — o afiliado APROVADO não vê como e quando recebe
|--------------------------------------------------------------------------
| Regra da parte (brief e docs/fase-3/afiliados.md §6): "o sistema calcula, não paga" — o repasse é
| manual, feito pela equipe FORA da plataforma, para saldos a partir do mínimo configurado.
|
| No portal da parceira de demonstração (Paula Parceira Demo, aprovada), a página mostra link,
| dados de repasse, os totais ("Pendentes", "A receber", "Pagas", "Revertidas") e "Libera em
| 14/10/2026" — mas em lugar nenhum diz que o pagamento é feito fora da plataforma, qual é o saldo
| mínimo para repasse nem quando/como o repasse acontece. Essas regras existem no componente
| `ProgramRules` (resources/js/pages/affiliates/index.tsx:75-110: "o repasse é feito pela equipe,
| fora da plataforma, para saldos a partir de R$ 50,00"), mas ele só é renderizado dentro do
| cartão de candidatura (`{canApply && …}`, index.tsx:344-363). Depois de aprovado, o afiliado
| perde o texto — justamente quando começa a ter saldo "A receber".
|
| Teste estrutural (a página é só front): o ramo `{active && affiliate && (…)}` precisa conter as
| regras do programa (ou, no mínimo, o aviso de repasse fora da plataforma).
*/

it('o ramo do afiliado aprovado mostra que o repasse é feito fora da plataforma e o saldo mínimo', function () {
    $source = (string) file_get_contents(base_path('resources/js/pages/affiliates/index.tsx'));

    $start = strpos($source, '{active && affiliate && (');
    expect($start)->not->toBeFalse('ramo do afiliado aprovado não encontrado em affiliates/index.tsx');

    $end = strpos($source, '<PayoutUpdateDialog', (int) $start);
    $branch = substr($source, (int) $start, ($end === false ? strlen($source) : $end) - (int) $start);

    $explainsPayout = str_contains($branch, '<ProgramRules')
        || (str_contains($branch, 'fora da plataforma') && str_contains($branch, 'min_payout_cents'));

    expect($explainsPayout)->toBeTrue('O afiliado aprovado não vê que o repasse é feito fora da plataforma nem o saldo mínimo (ProgramRules só aparece para quem ainda vai se candidatar).');
});
