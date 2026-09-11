<?php

namespace App\Services\Templates;

use App\Models\TemplateField;
use App\Models\TemplateRole;
use App\Models\TemplateVariable;
use App\Models\TemplateVersion;

/**
 * Definição JÁ VALIDADA de uma versão de modelo ({@see TemplateDefinitionBuilder}).
 *
 * Papéis são referenciados pelos campos por `ref` — o ULID do papel na versão anterior ou
 * um id temporário do editor —, porque cada versão nova ganha papéis novos.
 */
final class TemplateDefinition
{
    /**
     * @param  list<array{key: string, label: string, type: string, required: bool, help_text: string|null, default_value: string|null, options: array<string, mixed>|null}>  $variables
     * @param  list<array{ref: string, name: string, participant_role: string}>  $roles
     * @param  list<array{role_ref: string, type: string, page: int, x: float, y: float, width: float, height: float, box_type: string, page_width_pt: float, page_height_pt: float, page_rotation: int, required: bool, label: string|null, options: array<string, mixed>|null}>  $fields
     */
    public function __construct(
        public readonly ?string $htmlBody,
        public readonly string $signingOrder,
        public readonly array $variables,
        public readonly array $roles,
        public readonly array $fields,
    ) {}

    /**
     * sha256 da forma canônica: mesma definição sobre o mesmo arquivo ⇒ mesmo hash, e
     * salvar sem mudar nada não cria versão nova.
     */
    public function hash(?string $sourceIdentity): string
    {
        $roleIndex = array_flip(array_column($this->roles, 'ref'));

        $payload = [
            'source' => $sourceIdentity,
            'html' => $this->htmlBody,
            'signing_order' => $this->signingOrder,
            'variables' => $this->variables,
            'roles' => array_map(static fn (array $role): array => [$role['name'], $role['participant_role']], $this->roles),
            'fields' => array_map(static fn (array $field): array => [
                $roleIndex[$field['role_ref']] ?? -1,
                $field['type'],
                $field['page'],
                sprintf('%.6f', $field['x']),
                sprintf('%.6f', $field['y']),
                sprintf('%.6f', $field['width']),
                sprintf('%.6f', $field['height']),
                $field['required'],
                $field['label'],
                $field['options'],
            ], $this->fields),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Uma versão existente no MESMO formato que o editor envia (props do editor, duplicar,
     * trocar o arquivo). Papéis usam o próprio ULID como `ref`.
     *
     * @return array{html_body: string|null, signing_order: string|null, variables: list<array<string, mixed>>, roles: list<array<string, mixed>>, fields: list<array<string, mixed>>}
     */
    public static function inputFromVersion(TemplateVersion $version): array
    {
        $version->loadMissing(['variables', 'roles', 'fields']);

        $roleUlids = $version->roles->mapWithKeys(fn (TemplateRole $role): array => [$role->getKey() => $role->ulid])->all();

        return [
            'html_body' => $version->html_body,
            'signing_order' => $version->signingOrder()?->value,
            'variables' => array_values($version->variables->map(fn (TemplateVariable $variable): array => [
                'key' => $variable->key,
                'label' => $variable->label,
                'type' => $variable->type->value,
                'required' => $variable->required,
                'help_text' => $variable->help_text,
                'default_value' => $variable->default_value,
                'options' => $variable->options ?? (object) [],
            ])->all()),
            'roles' => array_values($version->roles->map(fn (TemplateRole $role): array => [
                'ref' => $role->ulid,
                'name' => $role->name,
                'participant_role' => $role->participant_role->value,
            ])->all()),
            'fields' => array_values($version->fields->map(fn (TemplateField $field): array => [
                'id' => $field->ulid,
                'role_ref' => $roleUlids[$field->template_role_id] ?? '',
                'type' => $field->type->value,
                'page' => $field->page,
                'x' => (float) $field->x,
                'y' => (float) $field->y,
                'w' => (float) $field->width,
                'h' => (float) $field->height,
                'required' => $field->required,
                'label' => $field->label,
                'options' => $field->options ?? (object) [],
            ])->all()),
        ];
    }
}
