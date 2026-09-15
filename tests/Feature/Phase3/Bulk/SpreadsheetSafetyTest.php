<?php

use App\Models\BulkGenerationRow;
use App\Models\Envelope;
use App\Services\BulkGeneration\BulkGenerationLimits;
use App\Services\BulkGeneration\BulkRowStatus;
use App\Services\BulkGeneration\Spreadsheet\ParsedSheet;
use App\Services\BulkGeneration\Spreadsheet\RejectedCell;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetReader;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetRejectedException;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| T6 na planilha: dado não confiável, nada é avaliado nem executado
|--------------------------------------------------------------------------
| As planilhas são geradas NO PRÓPRIO TESTE (XLSX montado à mão, com fórmulas reais em <f>).
*/

beforeEach(function () {
    $this->work = PdfFixtures::workspace();
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

/**
 * @param  array<string, int>  $overrides
 */
function bulkLimits(array $overrides = []): BulkGenerationLimits
{
    foreach ($overrides as $key => $value) {
        config()->set("assinavelox.bulk_generation.{$key}", $value);
    }

    return BulkGenerationLimits::for(null);
}

function bulkRead(string $path, string $extension, ?BulkGenerationLimits $limits = null): ParsedSheet
{
    return app(SpreadsheetReader::class)->read($path, $extension, $limits ?? bulkLimits());
}

function bulkRejectionCode(callable $callback): ?string
{
    try {
        $callback();
    } catch (SpreadsheetRejectedException $exception) {
        return $exception->errorCode;
    }

    return null;
}

test('XLSX: células de fórmula são recusadas — nem a fórmula nem o valor em cache são usados', function () {
    $path = bulkXlsx($this->work.'/formulas.xlsx', [[
        'rows' => [
            ['Nome', 'Soma', 'Link', 'Comando'],
            ['Ana', ['f' => '1+1', 'v' => '2'], ['f' => 'HYPERLINK("http://evil.example","clique")', 'v' => 'clique'], ['f' => "cmd|' /C calc'!A0", 'v' => '0']],
        ],
    ]]);

    $sheet = bulkRead($path, 'xlsx');
    $cells = $sheet->rows[0]['cells'];

    expect($sheet->headers)->toBe(['Nome', 'Soma', 'Link', 'Comando'])
        ->and($cells[0])->toBe('Ana')
        ->and($cells[1])->toBeInstanceOf(RejectedCell::class)
        ->and($cells[1]->reason)->toBe(RejectedCell::FORMULA)
        ->and($cells[2]->reason)->toBe(RejectedCell::FORMULA)
        ->and($cells[3]->reason)->toBe(RejectedCell::FORMULA);
});

test('XLSX: número, booleano e erro de planilha viram texto literal ou recusa', function () {
    $path = bulkXlsx($this->work.'/tipos.xlsx', [[
        'rows' => [
            ['Valor', 'Inteiro', 'Booleano', 'Erro', 'Texto com sinal'],
            [1234.5, 52998224725, true, ['e' => '#DIV/0!'], '=1+1'],
        ],
    ]]);

    $cells = bulkRead($path, 'xlsx')->rows[0]['cells'];

    // Texto "=1+1" numa célula de texto: o OpenSpout já o entrega como célula de fórmula — e ela
    // é recusada do mesmo jeito (nunca vira valor).
    expect($cells[0])->toBe('1234.5')
        ->and($cells[1])->toBe('52998224725')
        ->and($cells[2])->toBe('true')
        ->and($cells[3]->reason)->toBe(RejectedCell::SPREADSHEET_ERROR)
        ->and($cells[4])->toBeInstanceOf(RejectedCell::class)
        ->and($cells[4]->reason)->toBe(RejectedCell::FORMULA);
});

test('CSV: células que começam com = + - @ são recusadas; sinais numéricos legítimos passam', function () {
    $path = bulkCsv($this->work.'/injecao.csv', [
        ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
        ['=1+1', "+cmd|' /C calc'!A0", '-2+3+cmd|x', '@SUM(A1:A2)', '+55 11 91234-5678', '-12,5', "\t=HYPERLINK(\"http://x\")", 'texto normal'],
    ]);

    $cells = bulkRead($path, 'csv')->rows[0]['cells'];

    foreach ([0, 1, 2, 3, 6] as $index) {
        expect($cells[$index])->toBeInstanceOf(RejectedCell::class)
            ->and($cells[$index]->reason)->toBe(RejectedCell::FORMULA_LIKE);
    }

    expect($cells[4])->toBe('+55 11 91234-5678')
        ->and($cells[5])->toBe('-12,5')
        ->and($cells[7])->toBe('texto normal');
});

test('abas ocultas são ignoradas: vale a primeira aba visível', function () {
    $path = bulkXlsx($this->work.'/abas.xlsx', [
        ['name' => 'Oculta', 'hidden' => true, 'rows' => [['Segredo'], [['f' => 'WEBSERVICE("http://evil.example")', 'v' => 'x']]]],
        ['name' => 'Dados', 'rows' => [['Cabeçalho visível'], ['ok']]],
        ['name' => 'Outra', 'rows' => [['Não lida'], ['nunca']]],
    ]);

    $sheet = bulkRead($path, 'xlsx');

    expect($sheet->headers)->toBe(['Cabeçalho visível'])
        ->and($sheet->rows)->toHaveCount(1)
        ->and($sheet->rows[0]['cells'][0])->toBe('ok')
        ->and($sheet->hiddenSheetsIgnored)->toBeTrue();
});

test('limites: caracteres por célula, linhas e colunas', function () {
    $long = bulkCsv($this->work.'/longa.csv', [['Nome'], [str_repeat('a', 101)]]);
    $cell = bulkRead($long, 'csv', bulkLimits(['max_cell_chars' => 100]))->rows[0]['cells'][0];

    expect($cell)->toBeInstanceOf(RejectedCell::class)->and($cell->reason)->toBe(RejectedCell::TOO_LONG);

    $rows = bulkCsv($this->work.'/linhas.csv', [['Nome'], ['a'], ['b'], ['c'], ['d']]);
    expect(bulkRejectionCode(fn () => bulkRead($rows, 'csv', bulkLimits(['max_rows' => 3]))))->toBe('too_many_rows');

    $columns = bulkCsv($this->work.'/colunas.csv', [['a', 'b', 'c'], ['1', '2', '3']]);
    expect(bulkRejectionCode(fn () => bulkRead($columns, 'csv', bulkLimits(['max_columns' => 2]))))->toBe('too_many_columns');

    config()->set('assinavelox.bulk_generation.max_file_bytes', 2048);
    $big = bulkCsv($this->work.'/grande.csv', [['Nome'], [str_repeat('x', 3000)]]);
    expect(bulkRejectionCode(fn () => bulkRead($big, 'csv', BulkGenerationLimits::for(null))))->toBe('too_large');
});

test('XLSX inválido ou disfarçado é recusado pelo conteúdo, não pela extensão', function () {
    file_put_contents($this->work.'/texto.xlsx', "Nome;E-mail\nAna;ana@example.com\n");
    file_put_contents($this->work.'/pdf.xlsx', "%PDF-1.7\n1 0 obj<<>>endobj\n");
    file_put_contents($this->work.'/antigo.xlsx', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 512));
    file_put_contents($this->work.'/quebrado.xlsx', "PK\x03\x04".random_bytes(200));
    $docx = templateDocxFile($this->work.'/documento.xlsx', ['Olá']);
    $macro = bulkXlsx($this->work.'/macro.xlsx', [['rows' => [['Nome'], ['Ana']]]], ['xl/vbaProject.bin' => 'macro']);
    $zipAsCsv = bulkXlsx($this->work.'/planilha.csv', [['rows' => [['Nome'], ['Ana']]]]);
    file_put_contents($this->work.'/relatorio.pdf', '%PDF-1.7');

    expect(bulkRejectionCode(fn () => bulkRead($this->work.'/texto.xlsx', 'xlsx')))->toBe('invalid_xlsx')
        ->and(bulkRejectionCode(fn () => bulkRead($this->work.'/pdf.xlsx', 'xlsx')))->toBe('invalid_xlsx')
        ->and(bulkRejectionCode(fn () => bulkRead($this->work.'/antigo.xlsx', 'xlsx')))->toBe('legacy_or_protected')
        ->and(bulkRejectionCode(fn () => bulkRead($this->work.'/quebrado.xlsx', 'xlsx')))->toBe('invalid_xlsx')
        ->and(bulkRejectionCode(fn () => bulkRead($docx, 'xlsx')))->toBe('invalid_xlsx')
        ->and(bulkRejectionCode(fn () => bulkRead($macro, 'xlsx')))->toBe('macro')
        ->and(bulkRejectionCode(fn () => bulkRead($zipAsCsv, 'csv')))->toBe('invalid_csv')
        ->and(bulkRejectionCode(fn () => bulkRead($this->work.'/relatorio.pdf', 'pdf')))->toBe('unsupported_type');
});

test('XLSX com expansão suspeita (zip bomb) é recusado antes de abrir a planilha', function () {
    $bomb = bulkXlsx($this->work.'/bomba.xlsx', [['rows' => [['Nome'], ['Ana']]]], [
        'xl/media/zeros.bin' => str_repeat("\0", 3 * 1024 * 1024),
    ]);

    expect(filesize($bomb))->toBeLessThan(200 * 1024)
        ->and(bulkRejectionCode(fn () => bulkRead($bomb, 'xlsx', bulkLimits(['max_uncompressed_bytes' => 1024 * 1024]))))->toBe('too_large_uncompressed');
});

test('CSV: BOM removido, Windows-1252 decodificado, separador vírgula detectado, UTF-16 recusado', function () {
    $bom = bulkCsv($this->work.'/bom.csv', [['Nome', 'Endereço'], ['José', 'Rua São João, 10']]);
    $sheet = bulkRead($bom, 'csv');

    expect($sheet->headers)->toBe(['Nome', 'Endereço'])
        ->and($sheet->delimiter)->toBe(';')
        ->and($sheet->rows[0]['cells'])->toBe([0 => 'José', 1 => 'Rua São João, 10']);

    $ansi = bulkCsv($this->work.'/ansi.csv', [['Nome', 'Cidade'], ['Conceição', 'São Paulo']], ',', false, 'Windows-1252');
    $sheet = bulkRead($ansi, 'csv');

    expect(mb_check_encoding((string) file_get_contents($ansi), 'UTF-8'))->toBeFalse()
        ->and($sheet->delimiter)->toBe(',')
        ->and($sheet->rows[0]['cells'])->toBe([0 => 'Conceição', 1 => 'São Paulo']);

    file_put_contents($this->work.'/utf16.csv', "\xFF\xFE".mb_convert_encoding("Nome\nAna\n", 'UTF-16LE', 'UTF-8'));
    expect(bulkRejectionCode(fn () => bulkRead($this->work.'/utf16.csv', 'csv')))->toBe('utf16');
});

test('linhas vazias são puladas e a linha informada é a da planilha', function () {
    $path = bulkCsv($this->work.'/vazias.csv', [['Nome'], ['Ana'], [''], ['Bruno']]);
    $sheet = bulkRead($path, 'csv');

    expect(array_column($sheet->rows, 'line'))->toBe([2, 4]);
});

test('ponta a ponta: fórmula em coluna mapeada deixa a linha com erro claro, sem o valor e sem envelope', function () {
    $this->withoutVite();
    templatesWorkspace();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    bulkEnable($organization);
    actingAsMember($owner, $organization);

    $template = bulkHtmlTemplate($organization, $owner);
    $path = bulkXlsx($this->work.'/lote.xlsx', [[
        'rows' => [
            ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel'],
            ['Ana Souza', 'ana@example.com', ['f' => 'HYPERLINK("http://evil.example","Casa")', 'v' => 'Casa'], '529.982.247-25', '1.500,00'],
            ['Bruno Lima', ['f' => '"b"&"@example.com"', 'v' => 'b@example.com'], 'Apto', '529.982.247-25', '=1+1'],
            ['Carla Dias', 'carla@example.com', 'Sala', '529.982.247-25', '2.000,00'],
        ],
    ]]);

    $batch = bulkValidate(bulkUpload($template, bulkFile($path)));

    expect($batch->valid_count)->toBe(1)->and($batch->invalid_count)->toBe(2);

    $rows = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->orderBy('row_index')->get();
    $json = json_encode($rows->pluck('errors')->all(), JSON_UNESCAPED_UNICODE);

    expect($rows[0]->status)->toBe(BulkRowStatus::Invalid)
        ->and($rows[0]->errors[0]['header'])->toBe('Nome do imóvel')
        ->and($rows[0]->errors[0]['message'])->toContain('fórmula')
        ->and($rows[0]->payload)->toBeNull()
        ->and($rows[1]->status)->toBe(BulkRowStatus::Invalid)
        ->and(collect($rows[1]->errors)->pluck('header')->all())->toBe(['Locatário — e-mail', 'Valor do aluguel'])
        ->and($rows[2]->status)->toBe(BulkRowStatus::Valid)
        ->and($json)->not->toContain('evil.example')
        ->and($json)->not->toContain('b@example.com')
        ->and($json)->not->toContain('=1+1')
        ->and(Envelope::query()->count())->toBe(0);
});
