<?php

namespace App\Http\Requests\Templates;

use App\Models\Template;
use App\Services\Envelopes\FieldSync;
use App\Services\Templates\HtmlSanitizer;
use App\Services\Templates\TemplateDefinitionBuilder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT templates.update — só a forma; cada variável, papel e campo é validado (com
 * mensagens por item) em TemplateDefinitionBuilder.
 */
class UpdateTemplateRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:60'],
            'signing_order' => ['nullable', 'string', 'in:sequential,parallel'],
            'html_body' => ['nullable', 'string', 'max:'.HtmlSanitizer::MAX_LENGTH],
            'variables' => ['nullable', 'array', 'max:'.TemplateDefinitionBuilder::MAX_VARIABLES],
            'roles' => ['required', 'array', 'min:1', 'max:'.TemplateDefinitionBuilder::MAX_ROLES],
            'fields' => ['nullable', 'array', 'max:'.FieldSync::MAX_FIELDS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.required' => 'Adicione pelo menos um participante (papel) ao modelo.',
            'roles.min' => 'Adicione pelo menos um participante (papel) ao modelo.',
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
            'signing_order' => 'ordem de assinatura',
            'html_body' => 'texto do modelo',
            'variables' => 'variáveis',
            'roles' => 'participantes',
            'fields' => 'campos',
        ];
    }
}
