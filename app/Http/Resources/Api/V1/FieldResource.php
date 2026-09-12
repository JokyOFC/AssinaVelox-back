<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SigningField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Campo posicionado na API v1: geometria em frações [0,1] da página exibida (origem no canto
 * superior esquerdo, y para baixo — docs/campos-e-geometria.md), o mesmo formato aceito no
 * PUT. O VALOR preenchido pelo participante nunca sai (pode ser CPF ou outro dado pessoal).
 *
 * @mixin SigningField
 */
class FieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SigningField $field */
        $field = $this->resource;
        $options = is_array($field->options) ? $field->options : [];

        return [
            'id' => $field->ulid,
            'object' => 'field',
            'recipient_id' => $field->relationLoaded('recipient') ? $field->recipient?->ulid : null,
            'document_id' => $field->relationLoaded('documentVersion') ? $field->documentVersion?->document?->ulid : null,
            'type' => $field->type->value,
            'page' => (int) $field->page,
            'x' => (float) $field->x,
            'y' => (float) $field->y,
            'w' => (float) $field->width,
            'h' => (float) $field->height,
            'required' => (bool) $field->required,
            'label' => $field->label,
            'auto' => (bool) ($options['auto'] ?? false),
        ];
    }
}
