<?php

namespace App\Http\Requests\BulkGenerations;

use App\Services\BulkGeneration\BulkGenerationLimits;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetReader;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Envio da planilha. Aqui só a forma (arquivo, extensão, tamanho); o conteúdo é conferido
 * pelo SpreadsheetReader (formato real, estrutura, fórmulas). A autorização fica no controller.
 */
class StoreBulkGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = BulkGenerationLimits::for(CurrentOrganization::instance()->get());

        return [
            'file' => ['required', 'file', 'extensions:csv,xlsx,txt', 'max:'.max(1, intdiv($limits->maxFileBytes, 1024))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $limits = BulkGenerationLimits::for(CurrentOrganization::instance()->get());

        return [
            'file.required' => 'Escolha a planilha (CSV ou XLSX).',
            'file.file' => 'Escolha a planilha (CSV ou XLSX).',
            'file.uploaded' => 'O envio da planilha falhou. Tente de novo.',
            'file.extensions' => 'Envie a planilha em CSV ou XLSX.',
            'file.max' => 'O arquivo passa do limite de '.SpreadsheetReader::humanBytes($limits->maxFileBytes).'.',
        ];
    }
}
