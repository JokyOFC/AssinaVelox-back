<?php

use App\Models\Envelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final (experiência) — "Nova solicitação" cria lixo a cada clique
|--------------------------------------------------------------------------
| `GET /documentos/nova` não abre um formulário: cria um envelope de verdade
| (EnvelopeController::create, linhas 208-224, título fixo "Novo documento") e redireciona
| para `/documentos/{ulid}/editar?step=1`. O botão está em três lugares — barra lateral,
| cabeçalho de Documentos e cabeçalho do Dashboard.
|
| A única saída do assistente é o botão "Sair" (pages/envelopes/wizard.tsx:565), que é um
| `<Link href={envelopesIndex()}>`: navega e deixa o rascunho para trás. Não há "descartar",
| não há confirmação, e nada limpa rascunhos vazios depois.
|
| Observado no navegador: uma única visita a /documentos/nova, sem digitar nada, deixou
| "Novo documento · AV-00014" no topo da lista, mudou o contador do cabeçalho de
| "13 documentos" para "14 documentos" e a aba "Rascunhos" de 3 para 4. O código sequencial
| AV-00014 foi consumido — o próximo documento de verdade vira AV-00015, e a numeração que
| o cliente usa para se referir aos contratos passa a ter buracos.
|
| Duas consequências práticas: a lista de quem só foi "dar uma olhada" enche de "Novo
| documento" idênticos (excluir é possível, mas item a item, pelo menu da linha), e uma
| requisição GET altera estado — qualquer pré-busca, pré-renderização de aba ou varredura
| de link cria envelopes sem que ninguém tenha clicado.
*/

/*
| Ajuste de expectativa: ROUTES_AND_PAGES §1.2 define, para `envelopes.create`, "Cria
| imediatamente um Envelope(draft) vazio e redireciona para envelopes.edit (garante autosave
| e ID)". Exigir zero rascunho contrariava esse contrato. O defeito real é o **acúmulo** —
| um rascunho e um número de sequência por clique — e é isso que se afirma aqui: cliques
| repetidos reaproveitam o mesmo rascunho intocado.
*/
it('sair do assistente sem preencher nada não deixa rascunho vazio na lista', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    actingAsMember($owner, $organization);

    // Três cliques em "Nova solicitação" — a pessoa abriu, olhou e voltou.
    foreach (range(1, 3) as $ignored) {
        $this->withoutVite()->get(route('envelopes.create'))->assertRedirect();
        $this->withoutVite()->get(route('envelopes.index'))->assertOk();
    }

    $drafts = Envelope::query()
        ->where('organization_id', $organization->getKey())
        ->where('title', 'Novo documento')
        ->get();

    expect($drafts)->toHaveCount(
        1,
        "Ficaram {$drafts->count()} rascunhos vazios chamados \"Novo documento\" na lista."
    );

    // E a numeração que o cliente usa para se referir aos contratos não ganhou buracos.
    expect($drafts->first()->number)->toBe(1);
});

it('o assistente oferece um caminho para descartar o rascunho que ele mesmo criou', function () {
    $wizard = (string) file_get_contents(resource_path('js/pages/envelopes/wizard.tsx'));

    // "Sair" existe; "Descartar" (a rota envelopes.destroy já está pronta) não.
    expect($wizard)->toContain('Sair')
        ->and($wizard)->toContain('Descartar');
});
