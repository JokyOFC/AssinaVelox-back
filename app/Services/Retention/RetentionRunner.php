<?php

namespace App\Services\Retention;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\RetentionEvent;
use App\Models\RetentionPolicy;
use App\Models\RetentionRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aplica as políticas de retenção ativas (`retention:apply`, diário). Idempotente e em lotes:
 *
 *  - retoma recibos pendentes de execuções interrompidas;
 *  - para cada organização com política ATIVA e flag `retention_policies` ligada:
 *      - preservação da organização inteira → nada acontece (registrado 1x/dia);
 *      - por categoria de envelope, até `batch_size` candidatos já sem os preservados
 *        (preservados não "entopem" o lote), cada um por {@see EnvelopePurger};
 *      - fotos, dossiês e trilha por {@see CategorySweeper}.
 *
 * Um envelope que falha não interrompe os outros (fica para a próxima execução, com o recibo
 * pendente). `--dry-run` só conta.
 */
final class RetentionRunner
{
    public function __construct(
        private readonly EnvelopePurger $purger,
        private readonly CategorySweeper $sweeper,
        private readonly LegalHolds $holds,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, int>
     */
    public function run(?int $organizationId = null, bool $dryRun = false, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $summary = [
            'organizations' => 0,
            'envelopes_purged' => 0,
            'envelopes_candidates' => 0,
            'held' => 0,
            'identity_captures' => 0,
            'dossiers' => 0,
            'audit_events' => 0,
            'resumed' => 0,
            'failed' => 0,
        ];

        $run = $dryRun ? null : RetentionRun::query()->create(['dry_run' => false, 'status' => 'running', 'started_at' => $now]);

        try {
            if (! $dryRun) {
                $summary['resumed'] = $this->purger->resumePending($organizationId);
            }

            $policies = RetentionPolicy::withoutOrganizationScope()
                ->where('is_active', true)
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
                ->orderBy('id')
                ->get();

            foreach ($policies as $policy) {
                /** @var Organization|null $organization */
                $organization = Organization::query()->find($policy->organization_id);

                if ($organization === null || ! RetentionFeature::enabled($organization)) {
                    continue;
                }

                $summary['organizations']++;
                $this->applyTo($organization, $policy, $run, $dryRun, $now, $summary);
            }

            $run?->forceFill(['status' => 'completed', 'summary' => $summary, 'finished_at' => Carbon::now()])->save();
        } catch (Throwable $exception) {
            $run?->forceFill(['status' => 'failed', 'summary' => $summary, 'finished_at' => Carbon::now()])->save();

            throw $exception;
        }

        return $summary;
    }

    /**
     * Prévia (nada é apagado nem registrado): quantos itens a próxima execução apagaria com
     * estes prazos — para a confirmação da tela de retenção. Sem limite de lote; a preservação
     * é respeitada exatamente como na execução real.
     *
     * @return array{counts: array<string, int|null>, held: int, total: int, organization_held: bool}
     */
    public function preview(Organization $organization, RetentionPolicy $policy, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $organizationId = (int) $organization->getKey();
        $snapshot = $this->holds->snapshot($organizationId, $now);
        $counts = [];
        $held = 0;

        foreach (RetentionCategory::cases() as $category) {
            $counts[$category->value] = null;
        }

        if ($snapshot->organizationHold !== null) {
            return ['counts' => array_map(static fn (): int => 0, $counts), 'held' => 0, 'total' => 0, 'organization_held' => true];
        }

        foreach (RetentionCategory::envelopeCategories() as $category) {
            $days = RetentionPolicies::effectiveDays($policy, $category);

            if ($days === null) {
                continue;
            }

            $cutoff = $now->copy()->subDays($days);
            $held += $snapshot->isEmpty() ? 0 : $this->heldQuery($this->candidates($organizationId, $category, $cutoff, $now), $snapshot)->count();
            $counts[$category->value] = $this->excludeHeld($this->candidates($organizationId, $category, $cutoff, $now), $snapshot)->count();
        }

        $captureDays = RetentionPolicies::effectiveDays($policy, RetentionCategory::IdentityCapture);
        $dossierDays = RetentionPolicies::effectiveDays($policy, RetentionCategory::Dossier);
        $trailDays = RetentionPolicies::effectiveDays($policy, RetentionCategory::AuditTrail);

        if ($captureDays !== null) {
            $counts[RetentionCategory::IdentityCapture->value] = $this->sweeper->identityCaptures($organizationId, $captureDays, $snapshot, null, true, $now);
        }

        if ($dossierDays !== null) {
            $counts[RetentionCategory::Dossier->value] = $this->sweeper->dossiers($organizationId, $dossierDays, $snapshot, null, true, $now);
        }

        if ($trailDays !== null && RetentionConfig::auditTrailDeletionAllowed()) {
            $counts[RetentionCategory::AuditTrail->value] = $this->sweeper->auditTrail($organizationId, $trailDays, null, true, $now);
        }

        return [
            'counts' => $counts,
            'held' => $held,
            'total' => array_sum(array_map('intval', $counts)),
            'organization_held' => false,
        ];
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function applyTo(Organization $organization, RetentionPolicy $policy, ?RetentionRun $run, bool $dryRun, Carbon $now, array &$summary): void
    {
        $organizationId = (int) $organization->getKey();
        $snapshot = $this->holds->snapshot($organizationId, $now);

        if ($snapshot->organizationHold !== null) {
            if (! $dryRun) {
                $this->holds->recordBlocked($organizationId, 'retention', $snapshot->organizationHold);
            }

            $summary['held']++;

            return;
        }

        foreach (RetentionCategory::envelopeCategories() as $category) {
            $days = RetentionPolicies::effectiveDays($policy, $category);

            if ($days === null) {
                continue;
            }

            $cutoff = $now->copy()->subDays($days);

            $held = $snapshot->isEmpty() ? 0 : $this->heldQuery($this->candidates($organizationId, $category, $cutoff, $now), $snapshot)->count();

            if ($held > 0) {
                $summary['held'] += $held;

                if (! $dryRun) {
                    RetentionTrail::recordOncePerDay($organizationId, RetentionEvent::SKIPPED_BY_HOLD, null, [
                        'category' => $category->value,
                        'count' => $held,
                    ]);
                }
            }

            $candidates = $this->excludeHeld($this->candidates($organizationId, $category, $cutoff, $now), $snapshot)
                ->orderBy('id')
                ->limit(RetentionConfig::batchSize())
                ->get();

            foreach ($candidates as $envelope) {
                $summary['envelopes_candidates']++;

                if ($dryRun) {
                    continue;
                }

                try {
                    $result = $this->purger->purge($envelope, $category, $run);

                    if ($result['status'] === 'purged') {
                        $summary['envelopes_purged']++;
                    } elseif ($result['status'] === 'held') {
                        $summary['held']++;
                    }
                } catch (Throwable $exception) {
                    $summary['failed']++;

                    $this->logger->error('retention.purge.failed', [
                        'envelope' => $envelope->ulid,
                        'category' => $category->value,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $captureDays = RetentionPolicies::effectiveDays($policy, RetentionCategory::IdentityCapture);

        if ($captureDays !== null) {
            $summary['identity_captures'] += $this->sweeper->identityCaptures($organizationId, $captureDays, $snapshot, $run, $dryRun, $now);
        }

        $dossierDays = RetentionPolicies::effectiveDays($policy, RetentionCategory::Dossier);

        if ($dossierDays !== null) {
            $summary['dossiers'] += $this->sweeper->dossiers($organizationId, $dossierDays, $snapshot, $run, $dryRun, $now);
        }

        $trailDays = RetentionPolicies::effectiveDays($policy, RetentionCategory::AuditTrail);

        if ($trailDays !== null) {
            $summary['audit_events'] += $this->sweeper->auditTrail($organizationId, $trailDays, $run, $dryRun, $now);
        }
    }

    /**
     * @return Builder<Envelope>
     */
    private function candidates(int $organizationId, RetentionCategory $category, Carbon $cutoff, Carbon $now): Builder
    {
        $query = Envelope::withoutOrganizationScope()
            ->withTrashed()
            ->where('organization_id', $organizationId);

        $stamp = $cutoff->format('Y-m-d H:i:s');

        return match ($category) {
            RetentionCategory::Completed => $query
                ->where('status', EnvelopeStatus::Completed->value)
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $cutoff),
            RetentionCategory::TerminalOther => $query
                ->whereIn('status', [EnvelopeStatus::Refused->value, EnvelopeStatus::Expired->value, EnvelopeStatus::Canceled->value])
                ->whereRaw('COALESCE(refused_at, expired_at, canceled_at, updated_at) < ?', [$stamp]),
            default => $query
                ->whereIn('status', [EnvelopeStatus::Draft->value, EnvelopeStatus::Preparing->value, EnvelopeStatus::Ready->value])
                ->whereRaw('COALESCE(deleted_at, updated_at) < ?', [$stamp])
                // Envio agendado para o futuro não é rascunho abandonado.
                ->where(fn (Builder $inner) => $inner->whereNull('scheduled_send_at')->orWhere('scheduled_send_at', '<=', $now)),
        };
    }

    /**
     * @param  Builder<Envelope>  $query
     * @return Builder<Envelope>
     */
    private function excludeHeld(Builder $query, HoldSnapshot $snapshot): Builder
    {
        if ($snapshot->envelopeIds() !== []) {
            $query->whereNotIn('id', $snapshot->envelopeIds());
        }

        if ($snapshot->folderIds() !== []) {
            $query->where(fn (Builder $inner) => $inner->whereNull('folder_id')->orWhereNotIn('folder_id', $snapshot->folderIds()));
        }

        return $query;
    }

    /**
     * @param  Builder<Envelope>  $query
     * @return Builder<Envelope>
     */
    private function heldQuery(Builder $query, HoldSnapshot $snapshot): Builder
    {
        $envelopeIds = $snapshot->envelopeIds();
        $folderIds = $snapshot->folderIds();

        return $query->where(function (Builder $inner) use ($envelopeIds, $folderIds): void {
            $inner->whereIn('id', $envelopeIds === [] ? [0] : $envelopeIds);

            if ($folderIds !== []) {
                $inner->orWhereIn('folder_id', $folderIds);
            }
        });
    }
}
