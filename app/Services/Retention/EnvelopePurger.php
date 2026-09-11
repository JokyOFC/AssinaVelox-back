<?php

namespace App\Services\Retention;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\RetentionDeletion;
use App\Models\RetentionEvent;
use App\Models\RetentionRun;
use App\Services\Documents\DocumentStorage;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exclusão de UM envelope pela política de retenção (Fase 2 §2.19 — propagação, docs §7).
 *
 * Ordem, pensada para ser retomável e nunca deixar registro sem arquivo nem arquivo órfão:
 *
 *  1. lock por envelope (`retention:envelope:{id}`) — nunca dois workers no mesmo envelope;
 *  2. relê o envelope e recusa o que não está encerrado (`in_progress`/`finalizing` NUNCA
 *     saem: a finalização ou uma assinatura incremental pode estar gravando revisões);
 *  3. bloqueio de preservação ativo → não apaga, registra a tentativa (1x/dia);
 *  4. colhe os caminhos no disco (todas as versões, imagens de assinatura e de campo, fotos da
 *     captura e artefatos derivados — dossiês, carimbos) e grava o RECIBO `pending` com essa
 *     lista, os resumos finais (conforme a decisão da verificação) e as contagens;
 *  5. apaga as linhas numa transação curta (nenhuma chamada externa dentro dela);
 *  6. só DEPOIS do commit apaga os arquivos e o diretório do envelope; o recibo vira
 *     `completed`. Um arquivo que resiste deixa o recibo `pending` e a próxima execução
 *     termina o serviço ({@see self::resumePending()}).
 *
 * Preservado: o recibo (sem dado pessoal) e `audit_events` — a FK `envelope_id` da trilha é
 * nullOnDelete, então os eventos ficam, sem o envelope, até o prazo da categoria "Trilha de
 * auditoria". Nenhum UPDATE em `audit_events` é emitido pela aplicação (T7); quem anula a
 * coluna é a própria FK.
 */
final class EnvelopePurger
{
    /**
     * Tabelas com `envelope_id`, filhos antes dos pais. As que não existirem são ignoradas.
     *
     * @var list<string>
     */
    private const ENVELOPE_TABLES = [
        'identity_captures',
        'identity_capture_requirements',
        'recipient_pins',
        'in_person_turns',
        'in_person_sessions',
        'batch_signing_items',
        'signing_field_values',
        'signature_acceptances',
        'signing_fields',
        'auth_challenges',
        'signing_sessions',
        'recipient_access_links',
        'envelope_reminders',
        'envelope_tag',
        'template_usages',
        'delivery_attempts',
        'public_form_submissions',
        'recipients',
    ];

    /** Estados que já foram publicáveis na verificação pública. */
    private const PUBLISHED_STATUSES = [
        EnvelopeStatus::Completed,
        EnvelopeStatus::Refused,
        EnvelopeStatus::Expired,
        EnvelopeStatus::Canceled,
    ];

    /** @var array<string, bool> */
    private static array $columns = [];

    public function __construct(
        private readonly LegalHolds $holds,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{status: 'purged'|'held'|'skipped', deletion: RetentionDeletion|null}
     */
    public function purge(Envelope $envelope, RetentionCategory $category, ?RetentionRun $run = null, string $trigger = 'retention'): array
    {
        $lock = Cache::lock('retention:envelope:'.$envelope->getKey(), 600);

        if (! $lock->get()) {
            return ['status' => 'skipped', 'deletion' => null];
        }

        try {
            /** @var Envelope|null $fresh */
            $fresh = Envelope::withoutOrganizationScope()->withTrashed()->whereKey($envelope->getKey())->first();

            if ($fresh === null || in_array($fresh->status, [EnvelopeStatus::InProgress, EnvelopeStatus::Finalizing], true)) {
                return ['status' => 'skipped', 'deletion' => null];
            }

            $hold = $this->holds->coveringHold($fresh);

            if ($hold !== null) {
                $this->holds->recordBlocked((int) $fresh->organization_id, 'retention', $hold, $fresh);

                return ['status' => 'held', 'deletion' => null];
            }

            $organizationUlid = Organization::withTrashed()->whereKey($fresh->organization_id)->value('ulid');
            $paths = $this->paths($fresh);
            $deletion = $this->receipt($fresh, $category, $paths, $run, $trigger);

            DB::transaction(fn () => $this->deleteRows($fresh));

            $this->finish($deletion, is_string($organizationUlid) ? $organizationUlid : null);

            RetentionTrail::record(
                (int) $fresh->organization_id,
                RetentionEvent::ENVELOPE_PURGED,
                [
                    'category' => $category->value,
                    'trigger' => $trigger,
                    'receipt' => $deletion->ulid,
                    'counts' => $deletion->manifest['counts'] ?? [],
                    'files_pending' => ! $deletion->isCompleted(),
                ],
                subjectUlid: $fresh->ulid,
            );

            Permissions::forgetCounts((int) $fresh->organization_id);

            return ['status' => 'purged', 'deletion' => $deletion];
        } finally {
            $lock->release();
        }
    }

    /**
     * Termina recibos `pending` cujo envelope já saiu do banco (arquivo que resistiu, processo
     * interrompido entre o commit e a remoção dos arquivos). Idempotente.
     */
    public function resumePending(?int $organizationId = null): int
    {
        $resumed = 0;

        $pending = RetentionDeletion::withoutOrganizationScope()
            ->where('status', RetentionDeletion::STATUS_PENDING)
            ->where('subject_type', RetentionDeletion::SUBJECT_ENVELOPE)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($pending as $deletion) {
            $stillThere = Envelope::withoutOrganizationScope()->withTrashed()->where('ulid', $deletion->subject_ulid)->exists();

            // A transação não chegou a apagar: o envelope volta a ser candidato normalmente.
            if ($stillThere) {
                continue;
            }

            $organizationUlid = Organization::withTrashed()->whereKey($deletion->organization_id)->value('ulid');
            $this->finish($deletion, is_string($organizationUlid) ? $organizationUlid : null);

            if ($deletion->isCompleted()) {
                $resumed++;
            }
        }

        return $resumed;
    }

    /**
     * @param  list<string>  $paths
     */
    private function receipt(Envelope $envelope, RetentionCategory $category, array $paths, ?RetentionRun $run, string $trigger): RetentionDeletion
    {
        $mode = RetentionConfig::verificationMode();
        $published = $envelope->sent_at !== null
            && $envelope->verification_code !== null
            && in_array($envelope->status, self::PUBLISHED_STATUSES, true);

        $attributes = [
            'organization_id' => $envelope->organization_id,
            'category' => $category->value,
            'verification_code' => $published && $mode->keepsCode() ? $envelope->verification_code : null,
            'envelope_status' => $envelope->status->value,
            'reference_at' => $this->referenceAt($envelope, $category),
            'final_hashes' => $published && $mode->keepsHashes() ? $this->finalHashes($envelope) : null,
            'manifest' => ['counts' => $this->counts($envelope, $paths)],
            'pending_paths' => $paths,
            'status' => RetentionDeletion::STATUS_PENDING,
            'trigger' => $trigger,
            'retention_run_id' => $run?->getKey(),
        ];

        /** @var RetentionDeletion|null $existing */
        $existing = RetentionDeletion::withoutOrganizationScope()
            ->where('subject_type', RetentionDeletion::SUBJECT_ENVELOPE)
            ->where('subject_ulid', $envelope->ulid)
            ->first();

        if ($existing !== null) {
            // Retomada: soma os caminhos novos aos que ficaram de uma tentativa anterior.
            $attributes['pending_paths'] = array_values(array_unique(array_merge($existing->pending_paths ?? [], $paths)));
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        return RetentionDeletion::query()->create($attributes + [
            'subject_type' => RetentionDeletion::SUBJECT_ENVELOPE,
            'subject_ulid' => $envelope->ulid,
        ]);
    }

    private function finish(RetentionDeletion $deletion, ?string $organizationUlid): void
    {
        $disk = Storage::disk(DocumentStorage::DISK);
        $ok = true;

        foreach ($deletion->pending_paths ?? [] as $path) {
            if ($path === '') {
                continue;
            }

            try {
                $disk->delete($path);
            } catch (Throwable $exception) {
                $ok = false;
                $this->logger->warning('retention.purge.file_not_removed', [
                    'receipt' => $deletion->ulid,
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($organizationUlid !== null && $deletion->subject_ulid !== null && $deletion->subject_ulid !== '') {
            try {
                $disk->deleteDirectory(RetentionConfig::pathPrefix().'/'.$organizationUlid.'/envelopes/'.$deletion->subject_ulid);
            } catch (Throwable $exception) {
                $ok = false;
                $this->logger->warning('retention.purge.directory_not_removed', [
                    'receipt' => $deletion->ulid,
                    'exception' => $exception::class,
                ]);
            }
        }

        if (! $ok) {
            return;
        }

        $deletion->forceFill([
            'status' => RetentionDeletion::STATUS_COMPLETED,
            'pending_paths' => null,
            'purged_at' => Carbon::now(),
        ])->save();
    }

    private function deleteRows(Envelope $envelope): void
    {
        $id = (int) $envelope->getKey();

        // Dossiês EM LOTE que contêm este envelope (`envelope_id` nulo, ids em `envelope_ids`):
        // o ZIP já está na lista de arquivos do recibo; a linha vira `expired` sem caminho e
        // perde a referência ao envelope excluído. Os mantidos por preservação de OUTRO
        // envelope do lote ficam como estão (a preservação vence).
        foreach ($this->bulkDossiers($envelope) as $bulk) {
            if ($bulk['held']) {
                continue;
            }

            DB::table('dossier_exports')->where('id', $bulk['id'])->update([
                'status' => 'expired',
                'storage_path' => null,
                'envelope_ids' => json_encode(array_values(array_filter($bulk['envelope_ids'], static fn (int $other): bool => $other !== $id))),
                'purged_at' => $bulk['purged_at'] ?? Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        // Notificações do sino (canal `database`) guardam título, código e nome do participante
        // ligados ao ULID do envelope (em `envelope_ulid` ou na URL): saem junto.
        if ($this->hasTable('notifications')) {
            DB::table('notifications')->where('data', 'like', '%'.$envelope->ulid.'%')->delete();
        }

        // Artefatos derivados de outros itens (dossiês, carimbos, assinaturas de participante):
        // primeiro, porque podem apontar para versões e para o envelope com RESTRICT.
        foreach (RetentionConfig::derivedArtifacts() as $artifact) {
            if ($this->hasColumn($artifact['table'], 'envelope_id')) {
                DB::table($artifact['table'])->where('envelope_id', $id)->delete();
            }
        }

        $recordIds = DB::table('verification_records')->where('envelope_id', $id)->pluck('id')->all();

        if ($recordIds !== [] && $this->hasTable('verification_record_documents')) {
            DB::table('verification_record_documents')->whereIn('verification_record_id', $recordIds)->delete();
        }

        DB::table('verification_records')->where('envelope_id', $id)->delete();

        $acceptanceIds = DB::table('signature_acceptances')->where('envelope_id', $id)->pluck('id')->all();
        $sessionIds = DB::table('signing_sessions')->where('envelope_id', $id)->pluck('id')->all();
        $documentIds = DB::table('documents')->where('envelope_id', $id)->pluck('id')->all();

        if ($acceptanceIds !== [] && $this->hasTable('acceptance_documents')) {
            DB::table('acceptance_documents')->whereIn('signature_acceptance_id', $acceptanceIds)->delete();
        }

        if ($sessionIds !== [] && $this->hasTable('signing_session_documents')) {
            DB::table('signing_session_documents')->whereIn('signing_session_id', $sessionIds)->delete();
        }

        DB::table('envelopes')->where('id', $id)->update(['sent_document_version_id' => null, 'final_document_version_id' => null]);
        DB::table('documents')->where('envelope_id', $id)->update(['current_version_id' => null]);

        foreach (self::ENVELOPE_TABLES as $table) {
            if ($this->hasColumn($table, 'envelope_id')) {
                DB::table($table)->where('envelope_id', $id)->delete();
            }
        }

        if ($documentIds !== []) {
            DB::table('document_versions')->whereIn('document_id', $documentIds)->delete();
            DB::table('documents')->whereIn('id', $documentIds)->delete();
        }

        DB::table('envelopes')->where('id', $id)->delete();
    }

    /**
     * Todo arquivo do envelope no disco privado `documents`.
     *
     * @return list<string>
     */
    private function paths(Envelope $envelope): array
    {
        $id = (int) $envelope->getKey();
        $documentIds = DB::table('documents')->where('envelope_id', $id)->pluck('id')->all();

        $paths = array_merge(
            $documentIds === [] ? [] : DB::table('document_versions')
                ->whereIn('document_id', $documentIds)
                ->where('storage_disk', DocumentStorage::DISK)
                ->pluck('storage_path')
                ->all(),
            DB::table('signature_acceptances')->where('envelope_id', $id)->whereNotNull('signature_image_path')->pluck('signature_image_path')->all(),
            DB::table('signing_field_values')->where('envelope_id', $id)->whereNotNull('image_path')->pluck('image_path')->all(),
            $this->hasTable('identity_captures')
                ? DB::table('identity_captures')->where('envelope_id', $id)->whereNotNull('storage_path')->pluck('storage_path')->all()
                : [],
        );

        foreach (RetentionConfig::derivedArtifacts() as $artifact) {
            if (! $this->hasColumn($artifact['table'], 'envelope_id')) {
                continue;
            }

            foreach ($artifact['path_columns'] as $column) {
                if ($this->hasColumn($artifact['table'], $column)) {
                    $paths = array_merge($paths, DB::table($artifact['table'])->where('envelope_id', $id)->whereNotNull($column)->pluck($column)->all());
                }
            }
        }

        foreach ($this->bulkDossiers($envelope) as $bulk) {
            if (! $bulk['held'] && $bulk['storage_path'] !== null) {
                $paths[] = $bulk['storage_path'];
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($path): string => (string) $path, $paths),
            static fn (string $path): bool => $path !== '',
        )));
    }

    /**
     * Contagens do que sai — o recibo prova a exclusão sem guardar o conteúdo.
     *
     * @param  list<string>  $paths
     * @return array<string, int>
     */
    private function counts(Envelope $envelope, array $paths): array
    {
        $id = (int) $envelope->getKey();
        $documentIds = DB::table('documents')->where('envelope_id', $id)->pluck('id')->all();

        $counts = [
            'files' => count($paths),
            'documents' => count($documentIds),
            'document_versions' => $documentIds === [] ? 0 : DB::table('document_versions')->whereIn('document_id', $documentIds)->count(),
            'recipients' => DB::table('recipients')->where('envelope_id', $id)->count(),
            'signature_acceptances' => DB::table('signature_acceptances')->where('envelope_id', $id)->count(),
            'identity_captures' => $this->hasTable('identity_captures') ? DB::table('identity_captures')->where('envelope_id', $id)->count() : 0,
            'delivery_attempts' => DB::table('delivery_attempts')->where('envelope_id', $id)->count(),
            'verification_records' => DB::table('verification_records')->where('envelope_id', $id)->count(),
        ];

        foreach (RetentionConfig::derivedArtifacts() as $artifact) {
            if ($this->hasColumn($artifact['table'], 'envelope_id')) {
                $counts[$artifact['table']] = DB::table($artifact['table'])->where('envelope_id', $id)->count();
            }
        }

        $bulk = $this->bulkDossiers($envelope);
        $counts['bulk_dossier_exports'] = count(array_filter($bulk, static fn (array $row): bool => ! $row['held']));
        $counts['bulk_dossier_exports_kept_by_hold'] = count(array_filter($bulk, static fn (array $row): bool => $row['held']));
        $counts['notifications'] = $this->hasTable('notifications')
            ? DB::table('notifications')->where('data', 'like', '%'.$envelope->ulid.'%')->count()
            : 0;

        return $counts;
    }

    /**
     * Resumos SHA-256 dos arquivos finais publicados (um por documento), sem nomes.
     *
     * @return list<string>|null
     */
    private function finalHashes(Envelope $envelope): ?array
    {
        $record = DB::table('verification_records')->where('envelope_id', $envelope->getKey())->first(['id', 'final_sha256']);

        if ($record === null) {
            return null;
        }

        $perDocument = $this->hasTable('verification_record_documents')
            ? DB::table('verification_record_documents')->where('verification_record_id', $record->id)->orderBy('position')->pluck('final_sha256')->all()
            : [];

        $hashes = count($perDocument) > 1 ? $perDocument : [$record->final_sha256];

        $hashes = array_values(array_filter(
            array_map(static fn ($hash): string => strtolower((string) $hash), $hashes),
            static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
        ));

        return $hashes === [] ? null : $hashes;
    }

    private function referenceAt(Envelope $envelope, RetentionCategory $category): ?CarbonInterface
    {
        return match ($category) {
            RetentionCategory::Completed => $envelope->completed_at,
            RetentionCategory::TerminalOther => $envelope->refused_at ?? $envelope->expired_at ?? $envelope->canceled_at ?? $envelope->updated_at,
            default => $envelope->deleted_at ?? $envelope->updated_at,
        };
    }

    /**
     * Dossiês em lote da organização que contêm o envelope. `held` = outro envelope do lote
     * está preservado (o ZIP fica até a liberação; a expiração própria o apaga depois).
     *
     * @return list<array{id: int, storage_path: string|null, envelope_ids: list<int>, purged_at: mixed, held: bool}>
     */
    private function bulkDossiers(Envelope $envelope): array
    {
        if (! $this->hasColumn('dossier_exports', 'envelope_ids')) {
            return [];
        }

        $id = (int) $envelope->getKey();
        $organizationId = (int) $envelope->organization_id;
        $snapshot = null;
        $rows = [];

        $candidates = DB::table('dossier_exports')
            ->where('organization_id', $organizationId)
            ->whereNull('envelope_id')
            ->whereNotNull('envelope_ids')
            ->where('envelope_ids', 'like', '%'.$id.'%')
            ->orderBy('id')
            ->get(['id', 'storage_path', 'envelope_ids', 'purged_at']);

        foreach ($candidates as $row) {
            $ids = json_decode((string) $row->envelope_ids, true);
            $ids = is_array($ids) ? array_values(array_map('intval', $ids)) : [];

            if (! in_array($id, $ids, true)) {
                continue;
            }

            $snapshot ??= $this->holds->snapshot($organizationId);
            $others = array_values(array_filter($ids, static fn (int $other): bool => $other !== $id));

            $rows[] = [
                'id' => (int) $row->id,
                'storage_path' => is_string($row->storage_path) && $row->storage_path !== '' ? $row->storage_path : null,
                'envelope_ids' => $ids,
                'purged_at' => $row->purged_at,
                'held' => $this->holds->coveringAny($snapshot, $others) !== null,
            ];
        }

        return $rows;
    }

    private function hasTable(string $table): bool
    {
        return self::$columns[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        return self::$columns[$key] ??= ($this->hasTable($table) && Schema::hasColumn($table, $column));
    }

    /** Para os testes que criam/derrubam tabelas no meio da execução. */
    public static function forgetSchemaCache(): void
    {
        self::$columns = [];
    }
}
