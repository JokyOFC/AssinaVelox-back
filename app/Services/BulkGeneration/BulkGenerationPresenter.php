<?php

namespace App\Services\BulkGeneration;

use App\Enums\FieldType;
use App\Enums\Permission;
use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateRole;
use App\Models\TemplateVersion;
use App\Services\BulkGeneration\Spreadsheet\SheetCollector;
use App\Services\BulkGeneration\Spreadsheet\SpreadsheetReader;
use App\Services\Envelopes\Reminders\RemindersFeature;
use App\Services\Envelopes\Sending\ScheduledSend;
use App\Services\Plans\PlanLedger;
use App\Services\Signing\Channels\ChannelFeatures;
use App\Services\Templates\TemplatePresenter;
use App\Services\Templates\TemplateSourceType;
use App\Support\Timezones;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Props Inertia das telas do lote (tipos TS em resources/js/components/bulk-generations/types.ts).
 * Nenhum valor de planilha sai daqui: só cabeçalhos, contagens, mensagens de erro e o envelope
 * gerado (título e código), que o próprio remetente já vê em Documentos.
 */
final class BulkGenerationPresenter
{
    public const ROWS_PER_PAGE = 50;

    /**
     * @param  LengthAwarePaginator<int, BulkGeneration>  $batches
     * @return array<string, mixed>
     */
    public static function index(LengthAwarePaginator $batches): array
    {
        return [
            'batches' => array_values(array_map(fn (BulkGeneration $batch): array => self::summary($batch), $batches->items())),
            'pagination' => [
                'current_page' => $batches->currentPage(),
                'last_page' => $batches->lastPage(),
                'total' => $batches->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function create(Template $template, TemplateVersion $version, Organization $organization): array
    {
        $phone = ChannelFeatures::smsWhatsapp($organization);
        $limits = BulkGenerationLimits::for($organization);

        return [
            'template' => TemplatePresenter::summary($template) + [
                'version' => $version->version_number,
                'roles_count' => $version->roles->count(),
                'variables_count' => $version->variables->count(),
            ],
            'columns' => array_map(fn (array $target): array => [
                'label' => $target['label'],
                'group' => $target['group'],
                'required' => $target['required'],
            ], ColumnMapping::targets($version, $phone)),
            'direct_send' => self::directSend($template, $version),
            'conversion' => TemplatePresenter::conversion($template->source_type),
            'limits' => $limits->toArray() + ['max_file_label' => SpreadsheetReader::humanBytes($limits->maxFileBytes)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function show(BulkGeneration $batch, string $filter, int $page): array
    {
        /** @var Organization $organization */
        $organization = $batch->organization;
        $batch->loadMissing(['template', 'templateVersion.roles', 'templateVersion.variables', 'templateVersion.fields']);
        $version = $batch->templateVersion;
        $phone = ChannelFeatures::smsWhatsapp($organization);

        $mapping = [];

        foreach ($batch->mapping ?? [] as $entry) {
            $mapping[(string) $entry['column']] = $entry['target'];
        }

        $headers = [];

        foreach ($batch->headers ?? [] as $column => $label) {
            $headers[] = ['column' => $column, 'label' => $label, 'letter' => SheetCollector::columnLetter($column)];
        }

        $subscription = app(PlanLedger::class)->subscriptionFor((int) $organization->getKey());
        $membership = BulkGenerationAccess::membership();
        $user = auth()->user();
        $timezone = $organization->timezone !== '' ? $organization->timezone : Organization::DEFAULT_TIMEZONE;

        return [
            'batch' => self::summary($batch) + [
                'source_format' => $batch->source_format,
                'dry_run_at' => $batch->dry_run_at?->toIso8601String(),
                'canceled_at' => $batch->canceled_at?->toIso8601String(),
                'mode' => $batch->option('mode'),
                'mode_label' => self::modeLabel($batch->option('mode')),
                'scheduled_for_label' => self::scheduledLabel($batch->option('scheduled_for'), $timezone),
                'reserved' => app(BulkGenerationQuota::class)->reservedFor($batch),
                // Lote não confirmado é descartado sozinho depois deste prazo sem alteração.
                'discard_after_days' => BulkGenerationRetention::days(),
            ],
            'template' => [
                'id' => $batch->template->ulid,
                'name' => $batch->template->name,
                'source_label' => $batch->template->source_type->label(),
                'version' => $batch->template_version_number,
                'usable' => $batch->template->isUsable(),
            ],
            'headers' => $headers,
            'mapping' => (object) $mapping,
            'targets' => ColumnMapping::targets($version, $phone),
            'counts' => BulkGenerationProgress::counts($batch),
            'rows' => self::rows($batch, $filter, $page),
            'quota' => [
                'remaining' => $subscription?->remainingEnvelopes(),
                'needed' => (int) $batch->valid_count,
                'fits' => $subscription !== null && $subscription->hasEnvelopeQuotaAvailable((int) $batch->valid_count),
            ],
            'options' => [
                'can_send' => $membership?->hasPermission(Permission::SendEnvelopes) ?? false,
                'can_schedule' => ($membership?->hasPermission(Permission::SendEnvelopes) ?? false) && app(RemindersFeature::class)->enabledFor($organization),
                'direct_send' => self::directSend($batch->template, $version),
                'schedule' => ScheduledSend::limits() + [
                    'timezone_label' => Timezones::humanLabel($timezone),
                    'default_value' => Carbon::now()->setTimezone($timezone)->addDay()->setTime(9, 0)->format(ScheduledSend::INPUT_FORMAT),
                ],
            ],
            'can' => [
                'manage' => $user !== null && BulkGenerationAccess::canManage($user, $batch),
                'cancel' => $user !== null && $batch->status === BulkGenerationStatus::Running && BulkGenerationAccess::canCancel($user, $batch),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(BulkGeneration $batch): array
    {
        $processed = (int) $batch->created_count + (int) $batch->failed_count + (int) $batch->canceled_count;

        return [
            'id' => $batch->ulid,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'template' => $batch->relationLoaded('template')
                ? ['id' => $batch->template->ulid, 'name' => $batch->template->name]
                : null,
            'source_filename' => $batch->source_filename,
            'row_count' => (int) $batch->row_count,
            'valid_count' => (int) $batch->valid_count,
            'invalid_count' => (int) $batch->invalid_count,
            'created_count' => (int) $batch->created_count,
            'failed_count' => (int) $batch->failed_count,
            'canceled_count' => (int) $batch->canceled_count,
            'processed' => $processed,
            'created_by' => $batch->relationLoaded('creator') ? $batch->creator?->name : null,
            'created_at' => $batch->created_at?->toIso8601String(),
            'confirmed_at' => $batch->confirmed_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Envio direto só faz sentido quando o envelope NASCE pronto: modelo PDF com um campo de
     * assinatura obrigatório para cada participante que precisa assinar. Em HTML/DOCX os campos
     * são posicionados no editor, então os documentos ficam para revisão.
     *
     * @return array{available: bool, reason: string|null}
     */
    public static function directSend(Template $template, TemplateVersion $version): array
    {
        if ($template->source_type !== TemplateSourceType::Pdf) {
            return ['available' => false, 'reason' => 'Modelos Word e HTML não têm campos posicionados: os documentos são gerados para você posicionar os campos no editor antes de enviar.'];
        }

        $version->loadMissing(['roles', 'fields']);

        foreach ($version->roles as $role) {
            /** @var TemplateRole $role */
            if (! $role->participant_role->requiresSignatureField()) {
                continue;
            }

            $has = $version->fields->contains(fn (TemplateField $field): bool => (int) $field->template_role_id === (int) $role->getKey()
                && $field->type === FieldType::Signature
                && $field->required);

            if (! $has) {
                return ['available' => false, 'reason' => sprintf('O modelo não tem campo de assinatura para "%s". Posicione o campo no modelo para enviar direto.', $role->name)];
            }
        }

        return ['available' => true, 'reason' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rows(BulkGeneration $batch, string $filter, int $page): array
    {
        $query = BulkGenerationRow::withoutOrganizationScope()
            ->where('bulk_generation_id', $batch->getKey())
            ->with(['envelope' => fn ($relation) => $relation->withoutGlobalScopes()])
            ->orderBy('row_index');

        if ($filter === 'problems') {
            $query->where(function ($where): void {
                $where->whereIn('status', [BulkRowStatus::Invalid->value, BulkRowStatus::Failed->value])
                    ->orWhere('outcome', BulkRowOutcome::NotSent->value);
            });
        }

        $paginator = $query->paginate(self::ROWS_PER_PAGE, ['*'], 'pagina', max(1, $page));

        return [
            'filter' => $filter,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'data' => array_values(array_map(function (BulkGenerationRow $row): array {
                /** @var Envelope|null $envelope */
                $envelope = $row->envelope;

                return [
                    'line' => $row->row_index,
                    'status' => $row->status->value,
                    'status_label' => $row->status->label(),
                    'errors' => $row->errors ?? [],
                    'error' => $row->error,
                    'outcome' => $row->outcome?->value,
                    'outcome_label' => $row->outcome?->label(),
                    'outcome_message' => $row->outcome_message,
                    'envelope' => $envelope !== null ? [
                        'id' => $envelope->ulid,
                        'title' => $envelope->title,
                        'display_code' => $envelope->display_code,
                        'draft' => $envelope->status->isDraftLike(),
                        'status_label' => $envelope->status->label(),
                    ] : null,
                ];
            }, $paginator->items())),
        ];
    }

    private static function modeLabel(mixed $mode): ?string
    {
        return match ($mode) {
            'review' => 'Gerar para revisar antes de enviar',
            'send' => 'Enviar assim que cada documento for gerado',
            'schedule' => 'Agendar o envio',
            default => null,
        };
    }

    private static function scheduledLabel(mixed $raw, string $timezone): ?string
    {
        $at = ScheduledSend::parse($raw);

        return $at?->copy()->setTimezone($timezone)->format('d/m/Y \à\s H:i');
    }
}
