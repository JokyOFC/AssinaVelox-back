<?php

namespace App\Services\Retention;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\LegalHold;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RetentionDeletion;
use App\Models\RetentionPolicy;
use App\Services\Organizations\EnvelopeVisibility;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Props da tela Configurações › Retenção e o contrato do detalhe do documento (selo
 * "Preservado" + ações de preservar/liberar) — docs/fase-2/retencao-e-preservacao.md §10.
 */
final class RetentionPresenter
{
    public function __construct(
        private readonly RetentionPolicies $policies,
        private readonly LegalHolds $holds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function settings(Organization $organization, ?Membership $membership): array
    {
        $policy = $this->policies->forOrganization($organization);
        $mode = RetentionConfig::verificationMode();
        $canManage = RetentionAuthorization::canManageHolds($membership);

        $periods = [];

        foreach (RetentionCategory::cases() as $category) {
            $periods[$category->value] = $policy?->daysFor($category);
        }

        return [
            'enabled' => RetentionFeature::enabled($organization),
            'can' => [
                'configure' => RetentionAuthorization::canConfigure($membership),
                'manage_holds' => $canManage,
            ],
            'policy' => [
                'is_active' => $policy->is_active ?? false,
                'periods' => $periods,
                'updated_at' => $policy?->updated_at?->toIso8601String(),
                'updated_by' => $policy?->updatedBy?->name,
            ],
            'categories' => array_map(fn (RetentionCategory $category): array => [
                'key' => $category->value,
                'label' => $category->label(),
                'reference' => $category->reference(),
                'deletes' => $category->deletes(),
                'preserves' => $category->preserves(),
                'minimum_days' => RetentionConfig::minimumDays($category),
                'available' => $category !== RetentionCategory::AuditTrail || RetentionConfig::auditTrailDeletionAllowed(),
                'unavailable_reason' => $category === RetentionCategory::AuditTrail && ! RetentionConfig::auditTrailDeletionAllowed()
                    ? 'Nesta instalação a trilha de auditoria é mantida sem exclusão automática (registro somente de inclusão).'
                    : null,
            ], RetentionCategory::cases()),
            'limits' => ['max_days' => RetentionConfig::MAX_DAYS],
            'confirmation_phrase' => RetentionConfig::REDUCTION_CONFIRMATION,
            // Prévia do que a próxima execução apagaria com os prazos SALVOS (mesmo com a
            // política desativada) e quando ela roda. A confirmação da tela refaz a conta com
            // os prazos do formulário pelo endpoint `settings.retention.preview`.
            'deletion_preview' => $this->deletionPreview($organization, $policy),
            'verification' => [
                'mode' => $mode->value,
                'label' => $mode->label(),
                'description' => $mode->description(),
            ],
            'backups' => [
                'window_days' => RetentionConfig::backupWindowDays(),
                'text' => sprintf(
                    'As cópias de segurança não permitem apagar um documento isoladamente. Elas são substituídas em ciclo e, em até %d dias depois da exclusão, nenhuma cópia ativa contém mais o documento.',
                    RetentionConfig::backupWindowDays(),
                ),
            ],
            'holds' => $this->holdRows($organization, $membership, $canManage),
            'folders' => Folder::forOrganization($organization)
                ->orderBy('name')
                ->get(['ulid', 'name'])
                ->map(fn (Folder $folder): array => ['id' => $folder->ulid, 'name' => $folder->name])
                ->values()
                ->all(),
            'recent_deletions' => RetentionDeletion::forOrganization($organization)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (RetentionDeletion $deletion): array => [
                    'id' => $deletion->ulid,
                    'category' => $deletion->category,
                    'category_label' => RetentionCategory::tryFrom($deletion->category)?->label() ?? $deletion->category,
                    'subject_type' => $deletion->subject_type,
                    'status' => $deletion->status,
                    'trigger' => $deletion->trigger,
                    'purged_at' => ($deletion->purged_at ?? $deletion->created_at)?->toIso8601String(),
                    'counts' => (array) ($deletion->manifest['counts'] ?? []),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $periods  prazos do formulário (null = os salvos)
     * @return array{counts: array<string, int|null>, held: int, total: int, organization_held: bool, next_run_at: string, endpoint: string}
     */
    public function deletionPreview(Organization $organization, ?RetentionPolicy $policy, ?array $periods = null): array
    {
        $draft = $policy !== null ? $policy->replicate() : new RetentionPolicy;

        if ($periods !== null) {
            foreach (RetentionCategory::cases() as $category) {
                $value = $periods[$category->value] ?? null;
                $draft->setAttribute($category->column(), is_numeric($value) ? max(0, min(RetentionConfig::MAX_DAYS, (int) $value)) : null);
            }
        }

        return app(RetentionRunner::class)->preview($organization, $draft) + [
            'next_run_at' => self::nextRunAt()->toIso8601String(),
            'endpoint' => route('settings.retention.preview'),
        ];
    }

    /**
     * Próxima execução de `retention:apply` (routes/console.php: diária às 04:25, no fuso da
     * aplicação).
     */
    public static function nextRunAt(): CarbonInterface
    {
        $next = Carbon::now()->setTimeFromTimeString(self::DAILY_RUN_AT);

        return $next->isPast() ? $next->addDay() : $next;
    }

    public const DAILY_RUN_AT = '04:25';

    /**
     * Contrato do detalhe do documento (`envelopes.legal_hold.show`, JSON) — para o selo
     * "Preservado" e as ações de preservar/liberar. Quem chama já autorizou `view`.
     *
     * @return array<string, mixed>
     */
    public function forEnvelope(Envelope $envelope, ?Membership $membership): array
    {
        $organization = $envelope->organization;
        $featureEnabled = RetentionFeature::enabled($organization);
        $canManage = RetentionAuthorization::canManageHolds($membership);
        $holds = $this->holds->holdsCovering($envelope);

        return [
            'feature_enabled' => $featureEnabled,
            'preserved' => $holds->isNotEmpty(),
            'holds' => $holds->map(fn (LegalHold $hold): array => $this->holdRow($hold, $membership, $canManage))->values()->all(),
            'can' => [
                'place' => $featureEnabled && $canManage,
                'release' => $canManage,
            ],
            'retention' => $this->envelopeRetention($envelope),
            'endpoints' => [
                'show' => route('envelopes.legal_hold.show', ['envelope' => $envelope->ulid]),
                'store' => route('envelopes.legal_hold.store', ['envelope' => $envelope->ulid]),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function holdRows(Organization $organization, ?Membership $membership, bool $canManage): array
    {
        return array_values(LegalHold::forOrganization($organization)
            ->with(['envelope', 'folder', 'creator', 'releaser'])
            ->orderByRaw('CASE WHEN released_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (LegalHold $hold): array => $this->holdRow($hold, $membership, $canManage))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function holdRow(LegalHold $hold, ?Membership $membership, bool $canManage): array
    {
        $active = $hold->isActive();

        return [
            'id' => $hold->ulid,
            'scope' => $hold->scope->value,
            'scope_label' => $hold->scope->label(),
            'subject' => $this->subject($hold, $membership),
            'reason' => $hold->reason,
            'active' => $active,
            'starts_at' => $hold->starts_at->toIso8601String(),
            'ends_at' => $hold->ends_at?->toIso8601String(),
            'released_at' => $hold->released_at?->toIso8601String(),
            'created_by' => $hold->creator?->name,
            'released_by' => $hold->releaser?->name,
            'release_reason' => $hold->release_reason,
            'can_release' => $active && $canManage,
            'release_url' => route('legal_holds.release', ['legalHold' => $hold->ulid]),
        ];
    }

    private function subject(LegalHold $hold, ?Membership $membership): string
    {
        return match ($hold->scope) {
            LegalHoldScope::Organization => 'Todos os documentos da organização',
            LegalHoldScope::Folder => $hold->folder !== null ? 'Pasta “'.$hold->folder->name.'”' : 'Pasta excluída',
            LegalHoldScope::Envelope => match (true) {
                $hold->envelope === null => 'Documento excluído',
                $membership !== null && EnvelopeVisibility::canSee($membership, $hold->envelope) => $hold->envelope->display_code.' · '.$hold->envelope->title,
                default => $hold->envelope->display_code,
            },
        };
    }

    /**
     * @return array{category: string|null, category_label: string|null, policy_active: bool, eligible_at: string|null}
     */
    private function envelopeRetention(Envelope $envelope): array
    {
        $category = match (true) {
            $envelope->status === EnvelopeStatus::Completed => RetentionCategory::Completed,
            in_array($envelope->status, [EnvelopeStatus::Refused, EnvelopeStatus::Expired, EnvelopeStatus::Canceled], true) => RetentionCategory::TerminalOther,
            $envelope->status->isDraftLike() => RetentionCategory::Draft,
            default => null,
        };

        $policy = $this->policies->forOrganization((int) $envelope->organization_id);
        $days = $policy !== null && $category !== null ? RetentionPolicies::effectiveDays($policy, $category) : null;

        /** @var CarbonInterface|null $reference */
        $reference = match ($category) {
            RetentionCategory::Completed => $envelope->completed_at,
            RetentionCategory::TerminalOther => $envelope->refused_at ?? $envelope->expired_at ?? $envelope->canceled_at ?? $envelope->updated_at,
            RetentionCategory::Draft => $envelope->deleted_at ?? $envelope->updated_at,
            default => null,
        };

        return [
            'category' => $category?->value,
            'category_label' => $category?->label(),
            'policy_active' => ($policy->is_active ?? false) && RetentionFeature::enabled($envelope->organization),
            'eligible_at' => $days !== null && $reference !== null ? $reference->copy()->addDays($days)->toIso8601String() : null,
        ];
    }
}
