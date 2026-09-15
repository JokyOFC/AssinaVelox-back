<?php

use App\Models\BulkGenerationRow;
use App\Services\BulkGeneration\BulkRowStatus;
use App\Services\BulkGeneration\Spreadsheet\CellSanitizer;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Phase3/Bulk/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda F (entrada não confiável) — o saneamento da célula corrompe UTF-8
|--------------------------------------------------------------------------
| `CellSanitizer::text()` apara a célula com `trim($value, " \t\n\r\x0B\u{00A0}")`. O `trim` do
| PHP trabalha com BYTES: `"\u{00A0}"` vira os dois bytes C2 e A0, e cada um é tirado das pontas
| sozinho. Toda célula que começa com um caractere de U+0080 a U+00BF (C2 xx: "¿", "¡", "«", "§",
| "º", "°", "£"…) perde o primeiro byte, e toda célula que termina em um caractere cujo último
| byte é A0 ("à" = C3 A0, "Š", "Р"…) perde o último. O texto vira UTF-8 inválido.
|
| Consequências (planilha é dado não confiável, T6 — mas aqui é o dado LEGÍTIMO que quebra):
|  - valor de variável "§ 2º do contrato" ou "¿Cuándo?": o `payload` cifrado (`encrypted:array`)
|    não serializa em JSON e a pré-validação inteira cai com erro 500;
|  - título "Referente à": `preg_replace(/u)` devolve null e o título some sem aviso.
*/

it('mantém intacto o texto com acento ou sinal nas pontas da célula', function (string $value) {
    expect(CellSanitizer::text($value, 5000))->toBe($value);
})->with([
    'interrogação espanhola' => ['¿Cuándo empieza?'],
    'exclamação espanhola' => ['¡Hola!'],
    'aspas angulares' => ['«Contrato»'],
    'parágrafo' => ['§ 2º do contrato'],
    'crase no fim' => ['Referente à'],
]);

it('a pré-validação aceita uma célula que começa com "§" em vez de cair com erro 500', function () {
    $this->withoutVite();
    $work = templatesWorkspace();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    bulkEnable($organization);
    actingAsMember($owner, $organization);
    $template = bulkHtmlTemplate($organization, $owner);

    try {
        $path = bulkCsv($work.'/lote.csv', [
            ['VALOR DO ALUGUEL', 'locatario - nome', 'E-mail do Locatário', 'cpf_do_locatario', 'Nome do imóvel'],
            ['1.500,00', 'Ana Souza', 'ana@example.com', '529.982.247-25', '§ 2º do contrato'],
        ]);

        $batch = bulkUpload($template, bulkFile($path));

        $this->put(route('bulk_generations.mapping', $batch), ['mapping' => bulkSuggestedMapping($batch)])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        /** @var BulkGenerationRow $row */
        $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->getKey())->firstOrFail();

        expect($row->status)->toBe(BulkRowStatus::Valid)
            ->and($row->payload['values']['nome'] ?? null)->toBe('§ 2º do contrato');
    } finally {
        PdfFixtures::cleanup($work);
    }
});
