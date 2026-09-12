<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EnvelopeStatus;
use App\Support\CurrentOrganization;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/envelopes — filtros da listagem.
 *
 * `status` aceita um valor, vários separados por vírgula ou um array (`status[]=`). `folder` é o
 * ULID de uma pasta da organização. As datas são ISO-8601 (interpretadas em UTC quando sem fuso).
 */
class ListEnvelopesRequest extends CursorPageRequest
{
    protected function prepareForValidation(): void
    {
        $status = $this->query('status');

        if (is_string($status)) {
            $this->merge(['status' => array_values(array_filter(array_map('trim', explode(',', $status)), fn (string $value): bool => $value !== ''))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'status' => ['nullable', 'array', 'max:9'],
            'status.*' => ['string', Rule::in(array_column(EnvelopeStatus::cases(), 'value'))],
            'folder' => [
                'nullable', 'string', 'size:26',
                Rule::exists('folders', 'ulid')->where('organization_id', CurrentOrganization::instance()->id()),
            ],
            'q' => ['nullable', 'string', 'max:120'],
            'created_after' => ['nullable', 'date'],
            'created_before' => ['nullable', 'date'],
            'updated_after' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'situação',
            'status.*' => 'situação',
            'folder' => 'pasta',
            'q' => 'busca',
            'created_after' => 'criado depois de',
            'created_before' => 'criado antes de',
            'updated_after' => 'alterado depois de',
        ];
    }
}
