<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Template;
use App\Models\TemplateRole;
use App\Models\TemplateVariable;
use App\Services\Api\ApiFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Modelo na API v1. No detalhe, as variáveis (chave, tipo, obrigatoriedade) e os papéis
 * (ULID) que `POST /templates/{template}/envelopes` espera em `values` e `participants`.
 *
 * @mixin Template
 */
class TemplateResource extends JsonResource
{
    protected bool $detailed = false;

    public function detailed(bool $detailed = true): static
    {
        $this->detailed = $detailed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Template $template */
        $template = $this->resource;
        $version = $template->currentVersion;

        $data = [
            'id' => $template->ulid,
            'object' => 'template',
            'name' => $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'source_type' => $template->source_type->value,
            'status' => $template->status->value,
            'usable' => $template->isUsable(),
            'version' => $version?->version_number,
            'updated_at' => ApiFormat::date($template->updated_at),
        ];

        if (! $this->detailed || $version === null) {
            return $data;
        }

        $version->loadMissing(['variables', 'roles']);

        return $data + [
            'variables' => $version->variables->map(fn (TemplateVariable $variable): array => [
                'key' => $variable->key,
                'label' => $variable->label,
                'type' => $variable->type->value,
                'required' => (bool) $variable->required,
                'help_text' => $variable->help_text,
                'default_value' => $variable->default_value,
                'options' => $variable->options ?? (object) [],
            ])->values()->all(),
            'roles' => $version->roles->map(fn (TemplateRole $role): array => [
                'id' => $role->ulid,
                'name' => $role->name,
                'participant_role' => $role->participant_role->value,
                'participant_role_label' => $role->participant_role->label(),
            ])->values()->all(),
        ];
    }
}
