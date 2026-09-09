<?php

namespace App\Http\Requests\Envelopes;

use App\Enums\FieldType;
use App\Models\Envelope;
use App\Services\Envelopes\FieldSync;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT envelopes.fields.sync (ROUTES §2.6 passo 3).
 *
 * Aqui só o FORMATO. As regras que dependem do documento — página existente, destinatário
 * do envelope, limites [0,1], tamanho mínimo por tipo — ficam em `FieldSync`, que as
 * confere contra o `pages_meta` da versão exibida. Valores de página enviados pelo
 * navegador (`page_width_pt`, `page_rotation`…) são ignorados de propósito: não constam
 * das regras e nunca chegam ao banco.
 */
class SyncFieldsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $envelope = $this->route('envelope');

        return $envelope instanceof Envelope && $this->user()?->can('update', $envelope) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'initials_on_all_pages' => ['nullable', 'boolean'],
            'fields' => ['present', 'array', 'max:'.FieldSync::MAX_FIELDS],
            'fields.*.id' => ['nullable', 'string', 'size:26'],
            'fields.*.auto' => ['nullable', 'boolean'],
            'fields.*.recipient_id' => ['nullable', 'string', 'size:26'],
            'fields.*.recipient_client_id' => ['nullable', 'string', 'max:64'],
            'fields.*.type' => ['required', Rule::in(array_column(FieldType::cases(), 'value'))],
            'fields.*.page' => ['required'],
            'fields.*.x' => ['required', 'numeric'],
            'fields.*.y' => ['required', 'numeric'],
            'fields.*.w' => ['required', 'numeric'],
            'fields.*.h' => ['required', 'numeric'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.label' => ['nullable', 'string', 'max:120'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:60'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.font_size' => ['nullable', 'numeric'],
            'fields.*.options.date_format' => ['nullable', 'string', 'max:32'],
            'fields.*.options.default' => ['nullable', 'boolean'],
            'fields.*.options.placeholder' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fields' => 'campos',
            'initials_on_all_pages' => 'rubrica em todas as páginas',
            'fields.*.type' => 'tipo do campo',
            'fields.*.page' => 'página',
            'fields.*.x' => 'posição horizontal',
            'fields.*.y' => 'posição vertical',
            'fields.*.w' => 'largura',
            'fields.*.h' => 'altura',
            'fields.*.label' => 'rótulo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fields.max' => 'O documento aceita no máximo '.FieldSync::MAX_FIELDS.' campos.',
        ];
    }

    /**
     * @return array{initials_on_all_pages: bool, fields: list<array<string, mixed>>}
     */
    public function payload(): array
    {
        /** @var array{initials_on_all_pages?: mixed, fields?: array<int, array<string, mixed>>} $validated */
        $validated = $this->validated();

        return [
            'initials_on_all_pages' => filter_var($validated['initials_on_all_pages'] ?? false, FILTER_VALIDATE_BOOL),
            'fields' => array_values($validated['fields'] ?? []),
        ];
    }
}
