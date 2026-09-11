<?php

namespace App\Services\Retention;

use App\Models\RetentionDeletion;
use App\Models\RetentionEvent;
use App\Models\RetentionRun;
use App\Services\Documents\DocumentStorage;
use App\Services\Identity\Models\IdentityCapture;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Categorias que apagam ARTEFATOS soltos, sem apagar o envelope: fotos da captura, dossiês
 * gerados e trilha de auditoria sem documento. Cada varredura que apaga algo gera um recibo
 * (`retention_deletions`) e um evento, só com contagens.
 *
 * Nada coberto por preservação sai: fotos e dossiês de envelopes (ou pastas) preservados são
 * excluídos da consulta; com preservação da organização inteira o runner nem chega aqui.
 */
final class CategorySweeper
{
    private const TRAIL_CHUNK = 1000;

    private const TRAIL_MAX_CHUNKS = 50;

    /**
     * Fotos da captura: o ARQUIVO sai; a linha fica com `storage_path = null` e `purged_at`
     * (o aceite referencia o resumo — mesma regra de App\Services\Identity\CapturePurge).
     */
    public function identityCaptures(int $organizationId, int $days, HoldSnapshot $holds, ?RetentionRun $run, bool $dryRun, Carbon $now): int
    {
        if (! Schema::hasTable('identity_captures')) {
            return 0;
        }

        $query = IdentityCapture::withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->whereNotNull('storage_path')
            ->where('captured_at', '<', $now->copy()->subDays($days));

        $this->excludeHeld($query->getQuery(), 'envelope_id', $holds);

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;
        $disk = Storage::disk(DocumentStorage::DISK);

        $query->orderBy('id')->chunkById(200, function ($captures) use ($disk, $now, &$count): void {
            foreach ($captures as $capture) {
                if (is_string($capture->storage_path) && $capture->storage_path !== '') {
                    $disk->delete($capture->storage_path);
                }

                $capture->forceFill(['storage_path' => null, 'purged_at' => $now])->save();
                $count++;
            }
        });

        $this->receipt($organizationId, RetentionCategory::IdentityCapture, RetentionDeletion::SUBJECT_IDENTITY_CAPTURES, RetentionEvent::CAPTURES_PURGED, $count, $run);

        return $count;
    }

    /**
     * Dossiês gerados: arquivo + linha, nas tabelas derivadas marcadas `category = dossier`
     * ({@see RetentionConfig::derivedArtifacts()}). Tabela ausente = nada a fazer.
     */
    public function dossiers(int $organizationId, int $days, HoldSnapshot $holds, ?RetentionRun $run, bool $dryRun, Carbon $now): int
    {
        $count = 0;
        $disk = Storage::disk(DocumentStorage::DISK);

        foreach (RetentionConfig::derivedArtifacts() as $artifact) {
            $table = $artifact['table'];
            $dateColumn = $artifact['date_column'];

            if ($artifact['category'] !== RetentionCategory::Dossier->value
                || $dateColumn === null
                || ! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'organization_id')
                || ! Schema::hasColumn($table, $dateColumn)) {
                continue;
            }

            $query = DB::table($table)
                ->where('organization_id', $organizationId)
                ->where($dateColumn, '<', $now->copy()->subDays($days));

            if (Schema::hasColumn($table, 'envelope_id')) {
                $this->excludeHeld($query, 'envelope_id', $holds);
            }

            // Dossiê EM LOTE: `envelope_id` nulo e os envelopes em `envelope_ids` (JSON). A
            // consulta acima não os enxerga; a cobertura é conferida linha a linha (§3: nada
            // de um documento preservado sai, nem dentro de um lote).
            $filterBulk = ! $holds->isEmpty() && Schema::hasColumn($table, 'envelope_ids');

            if ($dryRun && ! $filterBulk) {
                $count += $query->count();

                continue;
            }

            $pathColumns = array_values(array_filter($artifact['path_columns'], fn (string $column): bool => Schema::hasColumn($table, $column)));
            $legalHolds = app(LegalHolds::class);

            foreach ($query->orderBy('id')->limit(1000)->get(array_merge(['id'], $pathColumns, $filterBulk ? ['envelope_ids'] : [])) as $row) {
                $row = (array) $row;

                if ($filterBulk) {
                    $ids = json_decode((string) ($row['envelope_ids'] ?? ''), true);
                    $hold = is_array($ids) ? $legalHolds->coveringAny($holds, array_values(array_map('intval', $ids))) : null;

                    if ($hold !== null) {
                        if (! $dryRun) {
                            $legalHolds->recordBlocked($organizationId, 'retention_dossier', $hold);
                        }

                        continue;
                    }
                }

                if ($dryRun) {
                    $count++;

                    continue;
                }

                foreach ($pathColumns as $column) {
                    $path = $row[$column] ?? null;

                    if (is_string($path) && $path !== '') {
                        try {
                            $disk->delete($path);
                        } catch (Throwable) {
                            // O arquivo pode já não existir; a linha sai mesmo assim.
                        }
                    }
                }

                DB::table($table)->where('id', $row['id'])->delete();
                $count++;
            }
        }

        if (! $dryRun) {
            $this->receipt($organizationId, RetentionCategory::Dossier, RetentionDeletion::SUBJECT_DOSSIERS, RetentionEvent::DOSSIERS_PURGED, $count, $run);
        }

        return $count;
    }

    /**
     * Trilha de auditoria SEM documento (envelope já excluído ou evento geral da conta), mais
     * velha que o prazo. Eventos de envelopes que ainda existem nunca saem por aqui. Só roda
     * quando a operadora permite ({@see RetentionConfig::auditTrailDeletionAllowed()}): exige
     * DELETE em `audit_events`, contra a recomendação append-only de seguranca-operacional §3.
     */
    public function auditTrail(int $organizationId, int $days, ?RetentionRun $run, bool $dryRun, Carbon $now): int
    {
        if (! RetentionConfig::auditTrailDeletionAllowed()) {
            return 0;
        }

        $base = fn (): QueryBuilder => DB::table('audit_events')
            ->where('organization_id', $organizationId)
            ->whereNull('envelope_id')
            ->where('occurred_at', '<', $now->copy()->subDays($days));

        if ($dryRun) {
            return $base()->count();
        }

        $count = 0;

        for ($i = 0; $i < self::TRAIL_MAX_CHUNKS; $i++) {
            $ids = $base()->orderBy('id')->limit(self::TRAIL_CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $count += DB::table('audit_events')->whereIn('id', $ids)->delete();
        }

        $this->receipt($organizationId, RetentionCategory::AuditTrail, RetentionDeletion::SUBJECT_AUDIT_TRAIL, RetentionEvent::AUDIT_TRAIL_PURGED, $count, $run);

        return $count;
    }

    private function excludeHeld(QueryBuilder $query, string $envelopeColumn, HoldSnapshot $holds): void
    {
        $envelopeIds = $holds->envelopeIds();
        $folderIds = $holds->folderIds();

        if ($envelopeIds !== []) {
            $query->where(fn (QueryBuilder $inner) => $inner->whereNull($envelopeColumn)->orWhereNotIn($envelopeColumn, $envelopeIds));
        }

        if ($folderIds !== []) {
            $query->where(fn (QueryBuilder $inner) => $inner->whereNull($envelopeColumn)->orWhereNotIn(
                $envelopeColumn,
                fn (QueryBuilder $sub) => $sub->select('id')->from('envelopes')->whereIn('folder_id', $folderIds),
            ));
        }
    }

    private function receipt(int $organizationId, RetentionCategory $category, string $subjectType, string $eventType, int $count, ?RetentionRun $run): void
    {
        if ($count === 0) {
            return;
        }

        $now = Carbon::now();

        $deletion = RetentionDeletion::query()->create([
            'organization_id' => $organizationId,
            'category' => $category->value,
            'subject_type' => $subjectType,
            'subject_ulid' => (string) Str::ulid(),
            'manifest' => ['counts' => [$subjectType => $count]],
            'status' => RetentionDeletion::STATUS_COMPLETED,
            'trigger' => 'retention',
            'retention_run_id' => $run?->getKey(),
            'purged_at' => $now,
        ]);

        RetentionTrail::record($organizationId, $eventType, ['category' => $category->value, 'count' => $count, 'receipt' => $deletion->ulid], subjectUlid: $deletion->subject_ulid);
    }
}
