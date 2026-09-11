<?php

namespace App\Http\Requests\Templates;

use App\Models\Template;
use App\Services\Templates\HtmlSanitizer;
use App\Services\Templates\TemplateSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST templates.store — formato apenas. O conteúdo do arquivo é inspecionado em
 * TemplateSourceIntake; o HTML, sanitizado em TemplateDefinitionBuilder.
 */
class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Template::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:60'],
            'source_type' => ['required', 'string', Rule::in(TemplateSourceType::values())],
            'file' => ['nullable', 'file', 'max:'.((int) config('assinavelox.upload.max_mb', 25) * 1024)],
            'html_body' => ['nullable', 'string', 'max:'.HtmlSanitizer::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'description' => 'descrição',
            'category' => 'categoria',
            'source_type' => 'tipo de modelo',
            'file' => 'arquivo',
            'html_body' => 'texto do modelo',
        ];
    }
}
