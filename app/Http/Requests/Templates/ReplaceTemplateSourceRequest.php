<?php

namespace App\Http\Requests\Templates;

use App\Models\Template;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST templates.source.update — novo arquivo DOCX/PDF para o modelo (gera nova versão).
 */
class ReplaceTemplateSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof Template && $this->user()?->can('update', $template) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.((int) config('assinavelox.upload.max_mb', 25) * 1024)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'arquivo'];
    }
}
