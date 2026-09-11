<?php

namespace App\Services\Templates;

use App\Enums\RecipientRole;
use App\Integrations\Pdf\PdfConverterManager;
use App\Models\Template;
use App\Models\TemplateRole;
use App\Models\TemplateVariable;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\FieldSync;
use App\Services\Envelopes\PageBox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Props das telas de modelos (contrato em docs/fase-2/modelos.md §7). Nada aqui expõe id
 * interno: só ULIDs.
 */
final class TemplatePresenter
{
    /**
     * Usos por modelo da organização (uma consulta agregada).
     *
     * @return array<int, array{uses: int, last_used_at: string|null}>
     */
    public static function usage(int $organizationId): array
    {
        return DB::table('template_usages')
            ->where('organization_id', $organizationId)
            ->selectRaw('template_id, count(*) as uses, max(created_at) as last_used_at')
            ->groupBy('template_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->template_id => [
                'uses' => (int) $row->uses,
                'last_used_at' => $row->last_used_at !== null ? Carbon::parse((string) $row->last_used_at)->toIso8601String() : null,
            ]])
            ->all();
    }

    /**
     * @param  Collection<int, Template>  $templates  com `currentVersion` (+ contagens)
     * @param  array<int, array{uses: int, last_used_at: string|null}>  $usage
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $templates, array $usage): array
    {
        return array_values($templates->map(function (Template $template) use ($usage): array {
            $version = $template->currentVersion;

            return [
                'id' => $template->ulid,
                'name' => $template->name,
                'description' => $template->description,
                'category' => $template->category,
                'source_type' => $template->source_type->value,
                'source_label' => $template->source_type->label(),
                'status' => $template->status->value,
                'version' => $version?->version_number,
                'roles_count' => (int) ($version->roles_count ?? 0),
                'variables_count' => (int) ($version->variables_count ?? 0),
                'fields_count' => (int) ($version->fields_count ?? 0),
                'page_count' => $version?->page_count,
                'uses' => $usage[$template->getKey()]['uses'] ?? 0,
                'last_used_at' => $usage[$template->getKey()]['last_used_at'] ?? null,
                'updated_at' => $template->updated_at?->toIso8601String(),
                'usable' => $template->isUsable(),
            ];
        })->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function editor(Template $template, TemplateVersion $version, User $user): array
    {
        $organization = $template->organization;
        $usage = DB::table('template_usages')
            ->where('template_id', $template->getKey())
            ->selectRaw('template_version_id, count(*) as uses')
            ->groupBy('template_version_id')
            ->pluck('uses', 'template_version_id');

        $versions = TemplateVersion::query()
            ->with('creator:id,name')
            ->where('template_id', $template->getKey())
            ->orderByDesc('version_number')
            ->limit(30)
            ->get()
            ->map(fn (TemplateVersion $item): array => [
                'id' => $item->ulid,
                'number' => $item->version_number,
                'created_at' => $item->created_at?->toIso8601String(),
                'created_by' => $item->creator?->name,
                'uses' => (int) ($usage[$item->getKey()] ?? 0),
                'is_current' => $item->getKey() === $version->getKey(),
                'original_filename' => $item->original_filename,
            ])
            ->all();

        $participantRoles = DomainFeatures::participantRoles($organization);

        return [
            'template' => self::summary($template),
            'version' => [
                'id' => $version->ulid,
                'number' => $version->version_number,
                'created_at' => $version->created_at?->toIso8601String(),
                'original_filename' => $version->original_filename,
                'size_bytes' => $version->size_bytes,
                'page_count' => $version->page_count,
                'pages' => self::pages($version),
                'placeholders' => array_values((array) ($version->settings['placeholders'] ?? [])),
            ],
            'definition' => TemplateDefinition::inputFromVersion($version),
            'versions' => $versions,
            'options' => [
                'variable_types' => VariableType::options(),
                'participant_roles' => array_map(static fn (RecipientRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'enabled' => $role === RecipientRole::Signer || $participantRoles,
                ], RecipientRole::cases()),
                'signing_orders' => [
                    ['value' => 'sequential', 'label' => 'Em ordem (um de cada vez)'],
                    ['value' => 'parallel', 'label' => 'Todos ao mesmo tempo'],
                ],
                'date_formats' => FieldSync::DATE_FORMATS,
            ],
            'limits' => [
                'max_variables' => TemplateDefinitionBuilder::MAX_VARIABLES,
                'max_roles' => TemplateDefinitionBuilder::MAX_ROLES,
                'max_fields' => FieldSync::MAX_FIELDS,
                'max_choices' => TemplateDefinitionBuilder::MAX_CHOICES,
                'role_name_max' => TemplateDefinitionBuilder::ROLE_NAME_MAX,
                'html_max_length' => HtmlSanitizer::MAX_LENGTH,
                'max_upload_bytes' => (int) config('assinavelox.upload.max_mb', 25) * 1024 * 1024,
                'field_minimums' => FieldGeometry::minimumsForProps(),
            ],
            'conversion' => self::conversion($template->source_type),
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $template),
                'use' => Gate::forUser($user)->allows('use', $template),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function useForm(Template $template, TemplateVersion $version): array
    {
        $version->loadMissing(['variables', 'roles', 'fields']);

        return [
            'template' => self::summary($template) + [
                'version' => $version->version_number,
                'version_id' => $version->ulid,
                'fields_count' => $version->fields->count(),
                'page_count' => $version->page_count,
            ],
            'variables' => $version->variables->map(fn (TemplateVariable $variable): array => [
                'key' => $variable->key,
                'label' => $variable->label,
                'type' => $variable->type->value,
                'required' => $variable->required,
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
            'defaults' => ['title' => $template->name],
            'conversion' => self::conversion($template->source_type),
        ];
    }

    /**
     * @param  Collection<int, Template>  $templates
     * @return list<array<string, mixed>>
     */
    public static function picker(Collection $templates): array
    {
        return array_values($templates->map(fn (Template $template): array => [
            'id' => $template->ulid,
            'name' => $template->name,
            'category' => $template->category,
            'source_type' => $template->source_type->value,
            'source_label' => $template->source_type->label(),
            'roles_count' => (int) ($template->currentVersion->roles_count ?? 0),
            'variables_count' => (int) ($template->currentVersion->variables_count ?? 0),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Template $template): array
    {
        return [
            'id' => $template->ulid,
            'name' => $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'source_type' => $template->source_type->value,
            'source_label' => $template->source_type->label(),
            'status' => $template->status->value,
            'supports_variables' => $template->source_type->supportsVariables(),
            'supports_fields' => $template->source_type->supportsFields(),
            'requires_file' => $template->source_type->requiresFile(),
        ];
    }

    /**
     * DOCX depende do LibreOffice. A interface diz a verdade quando ele não está configurado:
     * o documento gerado ficará com falha de conversão.
     *
     * @return array{required: bool, available: bool, message: string|null}
     */
    public static function conversion(TemplateSourceType $type): array
    {
        if ($type !== TemplateSourceType::Docx) {
            return ['required' => false, 'available' => true, 'message' => null];
        }

        try {
            $available = app(PdfConverterManager::class)->converterFor('docx')->isConfigured();
        } catch (\Throwable) {
            $available = false;
        }

        return [
            'required' => true,
            'available' => $available,
            'message' => $available ? null : 'A conversão de arquivos Word para PDF (LibreOffice) não está configurada nesta instalação. O documento gerado a partir deste modelo ficará com falha de processamento até que a conversão seja instalada; use um modelo HTML ou PDF enquanto isso.',
        ];
    }

    /**
     * Caixa exibida de cada página (mesmo formato de `pages_meta` do wizard).
     *
     * @return list<array<string, mixed>>
     */
    private static function pages(TemplateVersion $version): array
    {
        $pages = [];

        foreach ($version->pages_meta ?? [] as $index => $meta) {
            $pages[] = ['page' => $index + 1] + PageBox::fromPageMeta($meta)->toArray();
        }

        return $pages;
    }
}
