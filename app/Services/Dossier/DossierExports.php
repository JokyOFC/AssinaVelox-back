<?php

namespace App\Services\Dossier;

use App\Enums\EnvelopeStatus;
use App\Jobs\Dossier\BuildDossierExport;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\User;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Organizations\EnvelopeVisibility;
use App\Services\Timestamp\TimestampFeatures;
use App\Support\IpDisplay;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Pedidos de dossiê: idempotência, autorização, link de download e expiração.
 *
 * ## Idempotência por (envelope, versão final)
 *
 * A chave junta o envelope, as versões finais de cada documento, o formato do dossiê, a
 * política de exibição de IP/e-mail e se o manifesto é carimbado. Pedir de novo o mesmo
 * dossiê devolve o MESMO pedido: pronto (link novo), em preparação (nada é redisparado) ou,
 * se expirou/falhou, o mesmo registro é remontado. Duas requisições simultâneas colidem no
 * índice único e a segunda relê a primeira.
 *
 * ## Link com expiração
 *
 * O download exige sessão autenticada na organização, permissão sobre cada envelope E uma URL
 * assinada (APP_KEY) que vence em `expires_at`. Nada disso é gravado no banco. Depois do
 * prazo o arquivo é apagado ({@see self::purge()}).
 */
final class DossierExports
{
    /** Pedido em preparação há mais que isto é considerado travado e pode ser redisparado. */
    public const STALE_MINUTES = 30;

    public function requestSingle(Envelope $envelope, User $user): DossierExport
    {
        if ($envelope->status !== EnvelopeStatus::Completed) {
            throw new DossierException('O dossiê só está disponível para documentos concluídos.', 'envelope_not_completed');
        }

        $envelope->loadMissing('organization');

        $key = sprintf(
            'single:%d:%s:%s:ip-%s:tsa-%d',
            $envelope->getKey(),
            $this->finalVersionsSignature($envelope),
            DossierBuilder::FORMAT,
            IpDisplay::mode($envelope->organization),
            TimestampFeatures::operatorTsa() ? 1 : 0,
        );

        return $this->findOrStart($envelope->organization_id, $key, [
            'kind' => DossierExport::KIND_SINGLE,
            'envelope_id' => $envelope->getKey(),
            'envelope_count' => 1,
            'requested_by_user_id' => $user->getKey(),
        ]);
    }

    /**
     * @param  list<string>  $envelopeUlids
     */
    public function requestBulk(Membership $membership, User $user, array $envelopeUlids): DossierExport
    {
        $envelopes = EnvelopeVisibility::envelopes($membership)
            ->with('organization')
            ->whereIn('ulid', $envelopeUlids)
            ->where('status', EnvelopeStatus::Completed->value)
            ->orderBy('number')
            ->get()
            ->filter(fn (Envelope $envelope): bool => $user->can('download', $envelope))
            ->values();

        if ($envelopes->isEmpty()) {
            throw new DossierException('Nenhum documento concluído que você possa baixar foi selecionado.', 'nothing_visible');
        }

        $organization = $envelopes->first()->organization;
        $signature = $envelopes->map(fn (Envelope $envelope): string => $envelope->getKey().'='.$this->finalVersionsSignature($envelope))->implode(',');

        $key = 'bulk:'.$user->getKey().':'.hash('sha256', implode('|', [
            $signature,
            DossierBuilder::FORMAT,
            IpDisplay::mode($organization),
            TimestampFeatures::operatorTsa() ? 1 : 0,
        ]));

        return $this->findOrStart((int) $membership->organization_id, $key, [
            'kind' => DossierExport::KIND_BULK,
            'envelope_ids' => $envelopes->map(fn (Envelope $envelope): int => (int) $envelope->getKey())->all(),
            'envelope_count' => $envelopes->count(),
            'requested_by_user_id' => $user->getKey(),
        ]);
    }

    /**
     * Quem pode ver o status e baixar: o envelope (single) ou TODOS os envelopes do lote
     * (bulk, e só quem pediu). A visibilidade é conferida no momento — um acesso perdido
     * depois do pedido derruba o link.
     */
    public function canAccess(User $user, DossierExport $export): bool
    {
        $ids = $export->envelopeIds();

        if ($ids === []) {
            return false;
        }

        if ($export->kind === DossierExport::KIND_BULK && (int) $export->requested_by_user_id !== (int) $user->getKey()) {
            return false;
        }

        $envelopes = Envelope::forOrganization($export->organization_id)->whereIn('id', $ids)->get();

        if ($envelopes->count() !== count($ids)) {
            return false;
        }

        return $envelopes->every(fn (Envelope $envelope): bool => $user->can('download', $envelope));
    }

    public function downloadUrl(DossierExport $export): ?string
    {
        if (! $export->isReady() || $export->expires_at === null || $export->isExpired()) {
            return null;
        }

        return URL::temporarySignedRoute('dossiers.download', $export->expires_at, ['dossierExport' => $export->ulid]);
    }

    /**
     * Props/JSON de status para o front (contrato em docs/fase-2/carimbo-e-dossie.md §7).
     *
     * @return array<string, mixed>
     */
    public function statusProps(DossierExport $export): array
    {
        $expired = $export->isExpired();
        $status = $expired && $export->status === DossierExport::STATUS_READY ? DossierExport::STATUS_EXPIRED : $export->status;

        return [
            'id' => $export->ulid,
            'kind' => $export->kind,
            'status' => $status,
            'status_label' => match ($status) {
                DossierExport::STATUS_PENDING => 'Na fila',
                DossierExport::STATUS_BUILDING => 'Em preparação',
                DossierExport::STATUS_READY => 'Pronto para baixar',
                DossierExport::STATUS_FAILED => 'Falhou',
                default => 'Expirado',
            },
            'envelope_count' => $export->envelope_count,
            'size_bytes' => $export->isReady() && ! $expired ? $export->size_bytes : null,
            'sha256' => $export->isReady() && ! $expired ? $export->sha256 : null,
            'timestamp_status' => $export->timestamp_status,
            'timestamp_label' => match ($export->timestamp_status) {
                'granted' => 'Manifesto com carimbo do tempo da operadora — não é carimbo ICP-Brasil',
                'unavailable' => 'Sem carimbo: TSA da operadora indisponível na montagem',
                default => null,
            },
            'error' => $status === DossierExport::STATUS_FAILED ? 'Não foi possível montar o dossiê. Tente novamente.' : null,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_url' => $expired ? null : $this->downloadUrl($export),
            'status_url' => route('dossiers.show', ['dossierExport' => $export->ulid]),
        ];
    }

    /**
     * Apaga o arquivo gerado e marca o pedido como expirado (idempotente).
     */
    public function purge(DossierExport $export): void
    {
        if ($export->storage_path !== null) {
            Storage::disk($export->storage_disk ?? (string) config('assinavelox.dossier.disk', 'documents'))->delete($export->storage_path);
        }

        $export->forceFill([
            'status' => DossierExport::STATUS_EXPIRED,
            'storage_path' => null,
            'purged_at' => $export->purged_at ?? Carbon::now(),
        ])->save();
    }

    public function fileExists(DossierExport $export): bool
    {
        return $export->storage_path !== null
            && Storage::disk($export->storage_disk ?? (string) config('assinavelox.dossier.disk', 'documents'))->exists($export->storage_path);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findOrStart(int $organizationId, string $key, array $attributes): DossierExport
    {
        $existing = DossierExport::forOrganization($organizationId)->where('idempotency_key', $key)->first();

        if ($existing === null) {
            try {
                /** @var DossierExport $existing */
                $existing = DossierExport::withoutOrganizationScope()->create([
                    ...$attributes,
                    'organization_id' => $organizationId,
                    'idempotency_key' => $key,
                    'status' => DossierExport::STATUS_PENDING,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Corrida: outra requisição criou o mesmo pedido entre a leitura e a escrita.
                return DossierExport::forOrganization($organizationId)->where('idempotency_key', $key)->firstOrFail();
            }

            $this->dispatch($existing);

            return $existing;
        }

        if ($existing->isReady() && ! $existing->isExpired() && $this->fileExists($existing)) {
            return $existing;
        }

        $inFlight = in_array($existing->status, [DossierExport::STATUS_PENDING, DossierExport::STATUS_BUILDING], true)
            && $existing->updated_at !== null
            && $existing->updated_at->gt(Carbon::now()->subMinutes(self::STALE_MINUTES));

        if ($inFlight) {
            return $existing;
        }

        if ($existing->isReady() || $existing->status === DossierExport::STATUS_READY) {
            $this->purge($existing);
        }

        $existing->forceFill([
            ...$attributes,
            'status' => DossierExport::STATUS_PENDING,
            'error_code' => null,
            'storage_path' => null,
            'sha256' => null,
            'size_bytes' => null,
            'manifest_sha256' => null,
            'timestamp_status' => null,
            'timestamp_token_id' => null,
            'expires_at' => null,
            'purged_at' => null,
            'completed_at' => null,
        ])->save();

        $this->dispatch($existing);

        return $existing->refresh();
    }

    private function dispatch(DossierExport $export): void
    {
        try {
            BuildDossierExport::dispatch((int) $export->getKey());
        } catch (Throwable $exception) {
            // Fila `sync` (desenvolvimento) propaga a exceção do job: o pedido fica `failed`
            // em vez de derrubar a requisição; em fila real a falha fica no próprio job.
            report($exception);
            $export->refresh()->forceFill(['status' => DossierExport::STATUS_FAILED, 'error_code' => $export->error_code ?? 'unexpected_error'])->save();
        }
    }

    /**
     * Versões finais de todos os documentos do envelope ("versão final" da idempotência).
     */
    private function finalVersionsSignature(Envelope $envelope): string
    {
        $ids = EnvelopeDocuments::ordered($envelope)->map(fn ($document): string => (string) ($document->final_version_id ?? 0))->all();

        return ($envelope->final_document_version_id ?? 0).'-'.implode('.', $ids);
    }
}
