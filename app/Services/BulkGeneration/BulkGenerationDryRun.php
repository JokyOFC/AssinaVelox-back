<?php

namespace App\Services\BulkGeneration;

use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\TemplateVersion;
use App\Services\BulkGeneration\Spreadsheet\ParsedSheet;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetReader;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetRejectedException;
use App\Services\Signing\Channels\ChannelFeatures;
use App\Services\Templates\VariableValues;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pré-validação OBRIGATÓRIA (dry run): aplica o mapeamento a todas as linhas, com as regras
 * da geração, e grava o relatório por linha. Não cria envelope, não reserva cota e pode ser
 * refeita quantas vezes o remetente quiser enquanto o lote não for confirmado.
 *
 * O arquivo é relido do disco privado e conferido pelo sha256 (é o mesmo que foi enviado).
 */
final class BulkGenerationDryRun
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly BulkGenerationStorage $storage,
        private readonly VariableValues $values,
    ) {}

    /**
     * @param  array<array-key, mixed>  $mappingInput  coluna => destino
     *
     * @throws ValidationException
     */
    public function run(BulkGeneration $batch, array $mappingInput): BulkGeneration
    {
        if (! $batch->status->isEditable()) {
            throw ValidationException::withMessages(['mapping' => 'Este lote já foi confirmado e não pode mais ser alterado.']);
        }

        /** @var TemplateVersion|null $version */
        $version = TemplateVersion::query()->with(['variables', 'roles'])->whereKey($batch->template_version_id)->first();

        if ($version === null) {
            throw ValidationException::withMessages(['template' => 'O modelo deste lote não existe mais.']);
        }

        $organization = $batch->organization;
        $mapping = ColumnMapping::validate($mappingInput, $batch->headers ?? [], $version, ChannelFeatures::smsWhatsapp($organization));
        $limits = BulkGenerationLimits::for($organization);

        try {
            /** @var ParsedSheet $sheet */
            $sheet = $this->storage->withLocalCopy(
                $batch,
                fn (string $path): ParsedSheet => $this->reader->read($path, $batch->source_format, $limits),
            );
        } catch (SpreadsheetRejectedException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $validator = new RowValidator($this->values, $version, $mapping, $sheet->headers);
        $now = Carbon::now();
        $records = [];
        $valid = 0;

        foreach ($sheet->rows as $row) {
            $result = $validator->validate($row['cells']);
            $payload = null;

            if ($result['payload'] !== null) {
                try {
                    $payload = self::encryptPayload($result['payload']);
                } catch (JsonEncodingException) {
                    // Uma célula que não serializa nunca derruba o lote inteiro: a linha fica com erro.
                    $result = ['payload' => null, 'errors' => [[
                        'column' => null,
                        'header' => null,
                        'message' => 'Esta linha tem caracteres que não puderam ser lidos. Salve a planilha em UTF-8 e envie de novo.',
                    ]]];
                }
            }

            $ok = $payload !== null;
            $valid += $ok ? 1 : 0;

            $records[] = [
                'bulk_generation_id' => $batch->getKey(),
                'organization_id' => $batch->organization_id,
                'row_index' => $row['line'],
                'payload' => $payload,
                'status' => $ok ? BulkRowStatus::Valid->value : BulkRowStatus::Invalid->value,
                'errors' => $ok ? null : json_encode($result['errors'], JSON_UNESCAPED_UNICODE),
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($batch, $records, $mapping, $valid, $sheet, $now): void {
            BulkGenerationRow::withoutOrganizationScope()->where('bulk_generation_id', $batch->getKey())->delete();

            foreach (array_chunk($records, 200) as $chunk) {
                DB::table('bulk_generation_rows')->insert($chunk);
            }

            $batch->forceFill([
                'mapping' => $mapping,
                'status' => BulkGenerationStatus::Validated,
                'row_count' => $sheet->rowCount(),
                'valid_count' => $valid,
                'invalid_count' => $sheet->rowCount() - $valid,
                'dry_run_at' => $now,
            ])->save();
        });

        return $batch->refresh();
    }

    /**
     * Cifra pelo MESMO cast do model (`encrypted:array`), para a leitura pelo Eloquent.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function encryptPayload(array $payload): string
    {
        return (string) (new BulkGenerationRow)->forceFill(['payload' => $payload])->getAttributes()['payload'];
    }
}
