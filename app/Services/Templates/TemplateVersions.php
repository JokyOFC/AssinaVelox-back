<?php

namespace App\Services\Templates;

use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateRole;
use App\Models\TemplateVariable;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grava versões de modelo. Uma versão nasce completa (arquivo/HTML + variáveis + papéis +
 * campos) numa transação, com o número calculado sob lock do modelo, e passa a ser a
 * `current_version_id`. Nada é atualizado depois: a próxima edição cria outra versão.
 */
final class TemplateVersions
{
    /**
     * @param  array{storage_disk: string|null, storage_path: string|null, original_filename: string|null, mime_type: string|null, size_bytes: int|null, sha256: string|null, page_count: int|null, pages_meta: array<int, array<string, mixed>>|null, placeholders: list<string>}  $source
     */
    public function write(Template $template, TemplateDefinition $definition, array $source, ?User $user, ?string $versionUlid = null): TemplateVersion
    {
        return DB::transaction(function () use ($template, $definition, $source, $user, $versionUlid): TemplateVersion {
            /** @var Template $locked */
            $locked = Template::withoutOrganizationScope()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            $number = ((int) TemplateVersion::withoutOrganizationScope()
                ->where('template_id', $locked->getKey())
                ->max('version_number')) + 1;

            $settings = ['signing_order' => $definition->signingOrder];

            if ($source['placeholders'] !== []) {
                $settings['placeholders'] = $source['placeholders'];
            }

            $version = new TemplateVersion;
            $version->forceFill([
                'ulid' => $versionUlid ?? (string) Str::ulid(),
                'template_id' => $locked->getKey(),
                'organization_id' => $locked->organization_id,
                'version_number' => $number,
                'source_type' => $locked->source_type,
                'storage_disk' => $source['storage_disk'],
                'storage_path' => $source['storage_path'],
                'original_filename' => $source['original_filename'],
                'mime_type' => $source['mime_type'],
                'size_bytes' => $source['size_bytes'],
                'sha256' => $source['sha256'],
                'html_body' => $definition->htmlBody,
                'page_count' => $source['page_count'],
                'pages_meta' => $source['pages_meta'],
                'settings' => $settings,
                'definition_hash' => $definition->hash(self::identity($source)),
                'created_by_user_id' => $user?->getKey(),
            ])->save();

            $roleIds = [];

            foreach ($definition->roles as $position => $role) {
                $model = new TemplateRole;
                $model->forceFill([
                    'template_version_id' => $version->getKey(),
                    'organization_id' => $version->organization_id,
                    'name' => $role['name'],
                    'participant_role' => $role['participant_role'],
                    'position' => $position,
                ])->save();

                $roleIds[$role['ref']] = $model->getKey();
            }

            foreach ($definition->variables as $position => $variable) {
                $model = new TemplateVariable;
                $model->forceFill([
                    'template_version_id' => $version->getKey(),
                    'organization_id' => $version->organization_id,
                    'key' => $variable['key'],
                    'label' => $variable['label'],
                    'type' => $variable['type'],
                    'required' => $variable['required'],
                    'help_text' => $variable['help_text'],
                    'default_value' => $variable['default_value'],
                    'options' => $variable['options'],
                    'position' => $position,
                ])->save();
            }

            $sortOrder = [];

            foreach ($definition->fields as $field) {
                $slot = $field['page'];
                $sortOrder[$slot] = ($sortOrder[$slot] ?? 0) + 1;

                $model = new TemplateField;
                $model->forceFill([
                    'template_version_id' => $version->getKey(),
                    'template_role_id' => $roleIds[$field['role_ref']],
                    'organization_id' => $version->organization_id,
                    'type' => $field['type'],
                    'page' => $field['page'],
                    'x' => $field['x'],
                    'y' => $field['y'],
                    'width' => $field['width'],
                    'height' => $field['height'],
                    'box_type' => $field['box_type'],
                    'page_width_pt' => $field['page_width_pt'],
                    'page_height_pt' => $field['page_height_pt'],
                    'page_rotation' => $field['page_rotation'],
                    'required' => $field['required'],
                    'label' => $field['label'],
                    'options' => $field['options'],
                    'sort_order' => $sortOrder[$slot],
                ])->save();
            }

            $locked->forceFill([
                'current_version_id' => $version->getKey(),
                'updated_by_user_id' => $user?->getKey(),
            ])->save();

            $template->setRawAttributes($locked->getAttributes(), true);
            $template->setRelation('currentVersion', $version);

            return $version;
        });
    }

    /**
     * Arquivo/metadados de origem de uma versão existente (reaproveitados pela próxima).
     *
     * @return array{storage_disk: string|null, storage_path: string|null, original_filename: string|null, mime_type: string|null, size_bytes: int|null, sha256: string|null, page_count: int|null, pages_meta: array<int, array<string, mixed>>|null, placeholders: list<string>}
     */
    public static function sourceOf(TemplateVersion $version): array
    {
        $placeholders = $version->settings['placeholders'] ?? [];

        return [
            'storage_disk' => $version->storage_disk,
            'storage_path' => $version->storage_path,
            'original_filename' => $version->original_filename,
            'mime_type' => $version->mime_type,
            'size_bytes' => $version->size_bytes,
            'sha256' => $version->sha256,
            'page_count' => $version->page_count,
            'pages_meta' => $version->pages_meta,
            'placeholders' => is_array($placeholders) ? array_values(array_map('strval', $placeholders)) : [],
        ];
    }

    /**
     * @return array{storage_disk: null, storage_path: null, original_filename: null, mime_type: null, size_bytes: null, sha256: null, page_count: null, pages_meta: null, placeholders: list<string>}
     */
    public static function emptySource(): array
    {
        return [
            'storage_disk' => null,
            'storage_path' => null,
            'original_filename' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'sha256' => null,
            'page_count' => null,
            'pages_meta' => null,
            'placeholders' => [],
        ];
    }

    /**
     * @param  array{page_count: int|null, pages_meta: array<int, array<string, mixed>>|null, placeholders: list<string>}  $source
     * @return array{type: TemplateSourceType, page_count: int|null, pages_meta: array<int, array<string, mixed>>|null, placeholders: list<string>}
     */
    public static function context(array $source, TemplateSourceType $type): array
    {
        return [
            'type' => $type,
            'page_count' => $source['page_count'],
            'pages_meta' => $source['pages_meta'],
            'placeholders' => $source['placeholders'],
        ];
    }

    /**
     * @param  array{sha256: string|null}  $source
     */
    public static function identity(array $source): ?string
    {
        return $source['sha256'];
    }
}
