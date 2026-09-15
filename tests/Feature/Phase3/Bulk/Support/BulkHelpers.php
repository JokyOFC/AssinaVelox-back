<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da geração em lote (F-BULK, docs/fase-3/geracao-em-lote.md)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Models\BulkGeneration;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/../../../Phase2/Templates/Support/TemplateHelpers.php';

if (! function_exists('bulkEnable')) {
    /**
     * Liga `templates` e `bulk_generation` (config global E plano vigente).
     *
     * @param  array<string, mixed>  $planFeatures  chaves extras em `plans.features`
     */
    function bulkEnable(Organization $organization, array $planFeatures = [], bool $participantRoles = false): void
    {
        templatesEnable($organization, $participantRoles);
        config()->set('assinavelox.features.bulk_generation', true);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['bulk_generation'] = true;
        $plan->forceFill(['features' => array_merge($features, $planFeatures)])->save();
    }
}

if (! function_exists('bulkQuota')) {
    /**
     * Cota de envelopes do plano vigente (null = ilimitado) e contadores zerados.
     */
    function bulkQuota(Organization $organization, ?int $quota, int $used = 0): void
    {
        $subscription = $organization->currentSubscription()->with('plan')->first();
        $subscription->plan->forceFill(['envelope_quota' => $quota])->save();
        $subscription->forceFill(['envelopes_used' => $used, 'envelopes_reserved' => 0])->save();
    }
}

if (! function_exists('bulkCsv')) {
    /**
     * CSV escrito byte a byte (sem o fputcsv, para controlar exatamente o conteúdo).
     *
     * @param  list<list<string>>  $rows
     */
    function bulkCsv(string $path, array $rows, string $delimiter = ';', bool $bom = true, ?string $encoding = null): string
    {
        $lines = array_map(function (array $row) use ($delimiter): string {
            return implode($delimiter, array_map(function (string $cell) use ($delimiter): string {
                $needsQuotes = str_contains($cell, $delimiter) || str_contains($cell, '"') || str_contains($cell, "\n");

                return $needsQuotes ? '"'.str_replace('"', '""', $cell).'"' : $cell;
            }, $row));
        }, $rows);

        $content = implode("\r\n", $lines)."\r\n";

        if ($encoding !== null) {
            $content = (string) mb_convert_encoding($content, $encoding, 'UTF-8');
        }

        file_put_contents($path, ($bom ? "\xEF\xBB\xBF" : '').$content);

        return $path;
    }
}

if (! function_exists('bulkXlsx')) {
    /**
     * XLSX mínimo e REAL, montado à mão (controle total: fórmulas, abas ocultas, erros).
     *
     * Cada aba: ['name' => 'Plan1', 'hidden' => false, 'rows' => [[célula, ...], ...]].
     * Célula: string (texto inline), int|float (número), bool, null (vazia),
     * ['f' => '1+1', 'v' => '2'] (fórmula com valor em cache), ['e' => '#DIV/0!'] (erro).
     *
     * @param  list<array{name?: string, hidden?: bool, rows: list<list<mixed>>}>  $sheets
     * @param  array<string, string>  $extra  entradas adicionais do pacote
     */
    function bulkXlsx(string $path, array $sheets, array $extra = []): string
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $overrides = '';
        $workbookSheets = '';
        $rels = '';

        foreach ($sheets as $index => $sheet) {
            $number = $index + 1;
            $name = htmlspecialchars($sheet['name'] ?? "Plan{$number}", ENT_XML1);
            $state = ($sheet['hidden'] ?? false) ? ' state="hidden"' : '';
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$number}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $workbookSheets .= "<sheet name=\"{$name}\" sheetId=\"{$number}\"{$state} r:id=\"rId{$number}\"/>";
            $rels .= "<Relationship Id=\"rId{$number}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$number}.xml\"/>";

            $rowsXml = '';

            foreach ($sheet['rows'] as $r => $cells) {
                $line = $r + 1;
                $cellsXml = '';

                foreach ($cells as $c => $cell) {
                    $ref = bulkColumnLetter($c).$line;

                    $cellsXml .= match (true) {
                        $cell === null => '',
                        is_array($cell) && isset($cell['f']) => "<c r=\"{$ref}\"".(is_numeric($cell['v'] ?? '') ? '' : ' t="str"').'><f>'.htmlspecialchars($cell['f'], ENT_XML1).'</f><v>'.htmlspecialchars((string) ($cell['v'] ?? ''), ENT_XML1).'</v></c>',
                        is_array($cell) && isset($cell['e']) => "<c r=\"{$ref}\" t=\"e\"><v>".htmlspecialchars($cell['e'], ENT_XML1).'</v></c>',
                        is_bool($cell) => "<c r=\"{$ref}\" t=\"b\"><v>".($cell ? '1' : '0').'</v></c>',
                        is_int($cell) || is_float($cell) => "<c r=\"{$ref}\"><v>{$cell}</v></c>",
                        default => "<c r=\"{$ref}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">".htmlspecialchars((string) $cell, ENT_XML1).'</t></is></c>',
                    };
                }

                $rowsXml .= "<row r=\"{$line}\">{$cellsXml}</row>";
            }

            $zip->addFromString("xl/worksheets/sheet{$number}.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rowsXml.'</sheetData></worksheet>');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');

        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }
}

if (! function_exists('bulkColumnLetter')) {
    function bulkColumnLetter(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - $mod, 26);
        }

        return $letters;
    }
}

if (! function_exists('bulkFile')) {
    function bulkFile(string $path, ?string $name = null): UploadedFile
    {
        return new UploadedFile($path, $name ?? basename($path), null, null, true);
    }
}

if (! function_exists('bulkPdfTemplate')) {
    /**
     * Modelo PDF fixo com dois signatários e campo de assinatura para cada um — os envelopes
     * gerados nascem PRONTOS.
     */
    function bulkPdfTemplate(Organization $organization, User $user, string $work, string $name = 'Termo de vistoria'): Template
    {
        return templatePdf($organization, $user, $work, [
            ['ref' => 'locatario', 'name' => 'Locatário', 'participant_role' => 'signer'],
            ['ref' => 'locador', 'name' => 'Locador', 'participant_role' => 'signer'],
        ], [
            ['role_ref' => 'locatario', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
            ['role_ref' => 'locador', 'type' => 'signature', 'page' => 2, 'x' => 0.55, 'y' => 0.8, 'w' => 0.3, 'h' => 0.06],
        ], $name);
    }
}

if (! function_exists('bulkHtmlTemplate')) {
    /**
     * Modelo HTML com variáveis de vários tipos e um participante.
     */
    function bulkHtmlTemplate(Organization $organization, User $user): Template
    {
        return templateHtml($organization, $user, '<p>{{nome}} — {{cpf}} — {{valor}} — {{inicio}} — {{mobiliado}}</p>', [
            ['key' => 'nome', 'label' => 'Nome do imóvel', 'type' => 'text', 'required' => true],
            ['key' => 'cpf', 'label' => 'CPF do locatário', 'type' => 'cpf', 'required' => true],
            ['key' => 'valor', 'label' => 'Valor do aluguel', 'type' => 'currency', 'required' => true],
            ['key' => 'inicio', 'label' => 'Início', 'type' => 'date', 'required' => false],
            ['key' => 'mobiliado', 'label' => 'Mobiliado', 'type' => 'boolean', 'required' => false],
        ]);
    }
}

if (! function_exists('bulkPdfRows')) {
    /**
     * Planilha do modelo PDF (cabeçalho + N linhas válidas).
     *
     * @return list<list<string>>
     */
    function bulkPdfRows(int $count, int $offset = 0): array
    {
        $rows = [['Locatário — nome', 'Locatário — e-mail', 'Locador — nome', 'Locador — e-mail', 'Título do documento']];

        for ($i = 1; $i <= $count; $i++) {
            $n = $i + $offset;
            $rows[] = ["Inquilino {$n}", "inquilino{$n}@example.com", "Proprietário {$n}", "dono{$n}@example.com", "Vistoria {$n}"];
        }

        return $rows;
    }
}

if (! function_exists('bulkUpload')) {
    /**
     * Envia a planilha e devolve o lote criado.
     */
    function bulkUpload(Template $template, UploadedFile $file): BulkGeneration
    {
        $before = BulkGeneration::query()->max('id') ?? 0;

        test()->post(route('bulk_generations.store', $template), ['file' => $file])
            ->assertSessionHasNoErrors();

        return BulkGeneration::query()->where('id', '>', $before)->latest('id')->firstOrFail();
    }
}

if (! function_exists('bulkSuggestedMapping')) {
    /**
     * @return array<string, string>
     */
    function bulkSuggestedMapping(BulkGeneration $batch): array
    {
        $mapping = [];

        foreach ($batch->fresh()->mapping ?? [] as $entry) {
            $mapping[(string) $entry['column']] = $entry['target'];
        }

        return $mapping;
    }
}

if (! function_exists('bulkValidate')) {
    /**
     * Pré-validação com o mapeamento sugerido (ou o informado).
     *
     * @param  array<string, string>|null  $mapping
     */
    function bulkValidate(BulkGeneration $batch, ?array $mapping = null): BulkGeneration
    {
        test()->put(route('bulk_generations.mapping', $batch), ['mapping' => $mapping ?? bulkSuggestedMapping($batch)])
            ->assertSessionHasNoErrors();

        return $batch->fresh();
    }
}
