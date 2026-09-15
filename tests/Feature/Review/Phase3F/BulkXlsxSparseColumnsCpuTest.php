<?php

use App\Services\BulkGeneration\BulkGenerationLimits;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetReader;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetRejectedException;

require_once __DIR__.'/../../Phase3/Bulk/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda F (entrada não confiável) — XLSX pequeno que custa minutos de CPU
|--------------------------------------------------------------------------
| O `SpreadsheetReader` mede a expansão do ZIP (zip bomb) e limita linhas, colunas do CABEÇALHO e
| linhas vazias, mas não limita a LARGURA de cada linha lida. Uma célula na coluna XFD (16.384)
| faz o OpenSpout materializar 16.384 células vazias para aquela linha, e o laço de
| `SpreadsheetReader::readXlsx()` percorre todas antes de o `SheetCollector` descartar o que passa
| da largura do cabeçalho.
|
| Medido nesta máquina: 200 linhas assim (arquivo de ~3 KB) = 5 s; 800 linhas só com `<c r="XFD…"/>`
| vazia (5 KB, contam como "vazias", teto de 10.000) = 20 s. Com 1.000 linhas de dados + 10.000
| vazias, um arquivo de algumas dezenas de KB prende o PHP por minutos — e a leitura roda DENTRO
| da requisição HTTP, no envio (`store`) e de novo em cada pré-validação (`mapping`), com limite de
| 10 e 20 por minuto.
|
| O teste compara 300 linhas esparsas com 300 linhas normais: a leitura não pode custar mais que
| alguns décimos de segundo, nem ser dezenas de vezes mais cara que a de uma planilha comum.
*/

function reviewSparseLimits(): BulkGenerationLimits
{
    return new BulkGenerationLimits(1000, 5 * 1024 * 1024, 2, 3, 100, 5000, 50 * 1024 * 1024);
}

function reviewTimedRead(string $path): float
{
    $started = microtime(true);

    try {
        (new SpreadsheetReader)->read($path, 'xlsx', reviewSparseLimits());
    } catch (SpreadsheetRejectedException) {
        // Recusar a planilha esparsa também é uma resposta aceitável — desde que rápida.
    }

    return microtime(true) - $started;
}

it('uma célula na coluna XFD não faz a leitura de um XLSX de poucos KB custar segundos', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'review-sparse-'.bin2hex(random_bytes(4));
    mkdir($dir);

    try {
        $sparse = [['Nome', 'E-mail']];
        $narrow = [['Nome', 'E-mail']];

        for ($i = 1; $i <= 300; $i++) {
            $row = ["Pessoa {$i}", "p{$i}@example.com"];
            $row[16383] = 'x'; // coluna XFD (bulkXlsx usa a chave como índice da coluna)
            $sparse[] = $row;
            $narrow[] = ["Pessoa {$i}", "p{$i}@example.com"];
        }

        $sparsePath = bulkXlsx($dir.'/esparsa.xlsx', [['rows' => $sparse]]);
        $narrowPath = bulkXlsx($dir.'/normal.xlsx', [['rows' => $narrow]]);

        expect(filesize($sparsePath))->toBeLessThan(64 * 1024);

        $baseline = reviewTimedRead($narrowPath);
        $elapsed = reviewTimedRead($sparsePath);

        expect($elapsed)->toBeLessThan(max(1.0, $baseline * 20));
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
});
