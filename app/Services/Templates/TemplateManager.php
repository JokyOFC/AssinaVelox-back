<?php

namespace App\Services\Templates;

use App\Enums\AuditEventType;
use App\Enums\RecipientRole;
use App\Models\Organization;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Casos de uso do cadastro de modelos: criar, editar (nova versão), trocar o arquivo,
 * duplicar, arquivar e restaurar. A autorização fica na TemplatePolicy (controllers); aqui
 * ficam as regras de conteúdo e a trilha.
 */
final class TemplateManager
{
    public function __construct(
        private readonly TemplateSourceIntake $intake,
        private readonly TemplateDefinitionBuilder $builder,
        private readonly TemplateVersions $versions,
        private readonly TemplateStorage $storage,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, category?: string|null, source_type: string, html_body?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(Organization $organization, User $user, array $data, ?UploadedFile $file): Template
    {
        $type = TemplateSourceType::from($data['source_type']);

        if ($type->requiresFile() && $file === null) {
            throw ValidationException::withMessages(['file' => 'Envie o arquivo do modelo.']);
        }

        $stored = null;

        try {
            return DB::transaction(function () use ($organization, $user, $data, $file, $type, &$stored): Template {
                $template = new Template;
                $template->forceFill([
                    'organization_id' => $organization->getKey(),
                    'name' => trim($data['name']),
                    'description' => self::nullableText($data['description'] ?? null),
                    'category' => self::nullableText($data['category'] ?? null),
                    'source_type' => $type,
                    'status' => TemplateStatus::Active,
                    'created_by_user_id' => $user->getKey(),
                    'updated_by_user_id' => $user->getKey(),
                ])->save();
                $template->setRelation('organization', $organization);

                $versionUlid = $this->storage->newVersionUlid();
                $source = TemplateVersions::emptySource();

                if ($file !== null && $type->requiresFile()) {
                    $ingested = $this->intake->ingest($template, $file, $versionUlid);
                    $stored = $ingested->storagePath;
                    $source = $ingested->toSource();
                }

                $definition = $this->builder->build(
                    $organization,
                    $this->starter($type, $data, $source['placeholders']),
                    TemplateVersions::context($source, $type),
                );

                $this->versions->write($template, $definition, $source, $user, $versionUlid);

                TemplateAudit::record($template, AuditEventType::TemplateCreated, [
                    'source_type' => $type->value,
                    'version' => 1,
                ]);

                return $template;
            });
        } catch (Throwable $exception) {
            $this->forget($stored);

            throw $exception instanceof TemplateRejectedException ? $exception->toValidationException() : $exception;
        }
    }

    /**
     * Metadados (nome, descrição, categoria) são do cadastro; o conteúdo (variáveis, papéis,
     * campos, HTML, ordem) gera uma versão NOVA — só se algo mudou de fato.
     *
     * @param  array<string, mixed>  $data
     * @return array{version_created: bool, version: TemplateVersion}
     *
     * @throws ValidationException
     */
    public function update(Template $template, User $user, array $data): array
    {
        $current = $this->current($template);
        $source = TemplateVersions::sourceOf($current);

        $definition = $this->builder->build(
            $template->organization,
            $data,
            TemplateVersions::context($source, $template->source_type),
        );

        $changes = [];

        foreach (['name', 'description', 'category'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $key === 'name' ? trim((string) $data[$key]) : self::nullableText($data[$key]);

            if ($value !== '' && $value !== $template->getAttribute($key)) {
                $changes[$key] = $value;
            }
        }

        $isNew = $definition->hash(TemplateVersions::identity($source)) !== $current->definition_hash;

        $version = DB::transaction(function () use ($template, $user, $definition, $source, $changes, $isNew, $current): TemplateVersion {
            if ($changes !== []) {
                $template->forceFill($changes + ['updated_by_user_id' => $user->getKey()])->save();

                TemplateAudit::record($template, AuditEventType::TemplateUpdated, ['changed' => array_keys($changes)]);
            }

            if (! $isNew) {
                return $current;
            }

            $version = $this->versions->write($template, $definition, $source, $user);

            TemplateAudit::record($template, AuditEventType::TemplateVersionCreated, [
                'version' => $version->version_number,
                'previous_version' => $current->version_number,
            ]);

            return $version;
        });

        return ['version_created' => $isNew, 'version' => $version];
    }

    /**
     * Troca o arquivo de um modelo DOCX/PDF, preservando variáveis, papéis e campos. DOCX:
     * marcadores novos no arquivo viram variáveis de texto. PDF: campos que não cabem mais
     * (página inexistente ou tamanho de página diferente) são descartados — e contados.
     *
     * @return array{version: TemplateVersion, dropped_fields: int, added_variables: int}
     *
     * @throws ValidationException
     */
    public function replaceSource(Template $template, User $user, UploadedFile $file): array
    {
        if (! $template->source_type->requiresFile()) {
            throw ValidationException::withMessages(['file' => 'Modelos HTML não têm arquivo de origem.']);
        }

        $current = $this->current($template);
        $input = TemplateDefinition::inputFromVersion($current);
        $stored = null;

        try {
            return DB::transaction(function () use ($template, $user, $file, $current, $input, &$stored): array {
                $versionUlid = $this->storage->newVersionUlid();
                $ingested = $this->intake->ingest($template, $file, $versionUlid);
                $stored = $ingested->storagePath;
                $source = $ingested->toSource();

                $added = 0;
                $dropped = 0;

                if ($template->source_type === TemplateSourceType::Docx) {
                    $declared = array_column($input['variables'], 'key');

                    foreach ($ingested->placeholders as $key) {
                        if (! in_array($key, $declared, true)) {
                            $input['variables'][] = self::textVariable($key);
                            $added++;
                        }
                    }
                }

                if ($template->source_type === TemplateSourceType::Pdf) {
                    $kept = array_values(array_filter(
                        $input['fields'],
                        fn (array $field): bool => TemplateDefinitionBuilder::fieldFits($field, $ingested->pagesMeta, (int) $ingested->pageCount),
                    ));
                    $dropped = count($input['fields']) - count($kept);
                    $input['fields'] = $kept;
                }

                $definition = $this->builder->build($template->organization, $input, TemplateVersions::context($source, $template->source_type));
                $version = $this->versions->write($template, $definition, $source, $user, $versionUlid);

                TemplateAudit::record($template, AuditEventType::TemplateVersionCreated, [
                    'version' => $version->version_number,
                    'previous_version' => $current->version_number,
                    'source_replaced' => true,
                    'fields_dropped' => $dropped,
                    'variables_added' => $added,
                ]);

                return ['version' => $version, 'dropped_fields' => $dropped, 'added_variables' => $added];
            });
        } catch (Throwable $exception) {
            $this->forget($stored);

            throw $exception instanceof TemplateRejectedException ? $exception->toValidationException() : $exception;
        }
    }

    /**
     * Cópia independente: novo modelo (versão 1) com o conteúdo da versão atual. O arquivo é
     * COPIADO para a pasta do novo modelo — os dois nunca compartilham bytes.
     *
     * @throws ValidationException
     */
    public function duplicate(Template $template, User $user): Template
    {
        $current = $this->current($template);
        $input = TemplateDefinition::inputFromVersion($current);
        $copied = null;

        try {
            $copy = DB::transaction(function () use ($template, $user, $current, $input, &$copied): Template {
                $copy = new Template;
                $copy->forceFill([
                    'organization_id' => $template->organization_id,
                    'name' => self::copyName($template->name),
                    'description' => $template->description,
                    'category' => $template->category,
                    'source_type' => $template->source_type,
                    'status' => TemplateStatus::Active,
                    'created_by_user_id' => $user->getKey(),
                    'updated_by_user_id' => $user->getKey(),
                ])->save();
                $copy->setRelation('organization', $template->organization);

                $versionUlid = $this->storage->newVersionUlid();
                $source = TemplateVersions::sourceOf($current);

                if ($current->hasFile()) {
                    $target = $this->storage->pathFor($copy, $versionUlid, pathinfo((string) $current->storage_path, PATHINFO_EXTENSION) ?: 'bin');
                    $this->storage->disk()->copy((string) $current->storage_path, $target);
                    $copied = $target;
                    $source['storage_path'] = $target;
                }

                $definition = $this->builder->build($template->organization, $input, TemplateVersions::context($source, $copy->source_type));
                $this->versions->write($copy, $definition, $source, $user, $versionUlid);

                TemplateAudit::record($copy, AuditEventType::TemplateCreated, [
                    'source_type' => $copy->source_type->value,
                    'version' => 1,
                    'duplicated_from' => $template->ulid,
                ]);

                return $copy;
            });
        } catch (Throwable $exception) {
            $this->forget($copied);

            throw $exception;
        }

        TemplateAudit::record($template, AuditEventType::TemplateDuplicated, ['copy' => $copy->ulid]);

        return $copy;
    }

    public function archive(Template $template, User $user): void
    {
        if ($template->isArchived()) {
            return;
        }

        $template->forceFill([
            'status' => TemplateStatus::Archived,
            'archived_at' => Carbon::now(),
            'updated_by_user_id' => $user->getKey(),
        ])->save();

        TemplateAudit::record($template, AuditEventType::TemplateArchived);
    }

    public function restore(Template $template, User $user): void
    {
        if (! $template->isArchived()) {
            return;
        }

        $template->forceFill([
            'status' => TemplateStatus::Active,
            'archived_at' => null,
            'updated_by_user_id' => $user->getKey(),
        ])->save();

        TemplateAudit::record($template, AuditEventType::TemplateRestored);
    }

    // -- Apoio ------------------------------------------------------------------------

    private function current(Template $template): TemplateVersion
    {
        $version = $template->currentVersion()->with(['variables', 'roles', 'fields'])->first();

        if (! $version instanceof TemplateVersion) {
            throw ValidationException::withMessages(['template' => 'Este modelo não tem conteúdo salvo.']);
        }

        return $version;
    }

    /**
     * Definição inicial de um modelo recém-criado: um participante "Signatário" e, conforme a
     * fonte, as variáveis encontradas no DOCX ou um texto HTML de partida.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $placeholders
     * @return array<string, mixed>
     */
    private function starter(TemplateSourceType $type, array $data, array $placeholders): array
    {
        $input = [
            'roles' => [['ref' => 'role-1', 'name' => RecipientRole::Signer->label(), 'participant_role' => RecipientRole::Signer->value]],
            'variables' => [],
            'fields' => [],
            'html_body' => null,
        ];

        if ($type === TemplateSourceType::Docx) {
            $input['variables'] = array_map(self::textVariable(...), $placeholders);
        }

        if ($type === TemplateSourceType::Html) {
            $html = trim((string) ($data['html_body'] ?? ''));

            if ($html === '') {
                $html = '<h1>'.htmlspecialchars(trim($data['name']), ENT_QUOTES | ENT_HTML5, 'UTF-8').'</h1>'
                    ."\n<p>Eu, {{nome_completo}}, inscrito(a) no CPF {{cpf}}, declaro que li e concordo com os termos abaixo.</p>"
                    ."\n<p>Escreva aqui o texto do documento.</p>";

                $input['variables'] = [
                    ['key' => 'nome_completo', 'label' => 'Nome completo', 'type' => VariableType::Text->value, 'required' => true],
                    ['key' => 'cpf', 'label' => 'CPF', 'type' => VariableType::Cpf->value, 'required' => true],
                ];
            } else {
                $input['variables'] = array_map(self::textVariable(...), PlaceholderEngine::scanHtml($html)['keys']);
            }

            $input['html_body'] = $html;
        }

        return $input;
    }

    /**
     * @return array{key: string, label: string, type: string, required: bool}
     */
    private static function textVariable(string $key): array
    {
        return [
            'key' => $key,
            'label' => Str::ucfirst(str_replace('_', ' ', $key)),
            'type' => VariableType::Text->value,
            'required' => true,
        ];
    }

    private static function copyName(string $name): string
    {
        $suffix = ' (cópia)';

        return mb_strlen($name) + mb_strlen($suffix) <= 160
            ? $name.$suffix
            : mb_substr($name, 0, 160 - mb_strlen($suffix)).$suffix;
    }

    private static function nullableText(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private function forget(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->storage->disk()->delete($path);
        } catch (Throwable) {
            // Órfão no disco é preferível a mascarar a exceção original.
        }
    }
}
