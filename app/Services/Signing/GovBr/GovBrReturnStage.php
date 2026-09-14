<?php

namespace App\Services\Signing\GovBr;

use App\Models\Document;
use App\Models\Envelope;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Pontos de encaixe da devolução gov.br na FINALIZAÇÃO (P3-GOV) — e as transições de prazo.
 *
 * O `EnvelopeFinalizer` (fora da área do P3-GOV) ainda NÃO chama esta classe; por isso a flag
 * tem a trava `assinavelox.govbr.finalizer_integration` ({@see GovBrReturnFeature}). O que a
 * integração precisa fazer está em docs/fase-3/gov-br.md §8, em resumo:
 *
 * 1. entrar no caminho de revisões incrementais quando `activeFor()` (além dos pedidos A1);
 * 2. continuar em `finalizing` enquanto `awaiting()`;
 * 3. somar `signatureCount()` à quantidade esperada de assinaturas da cadeia de cada documento;
 * 4. mapear `kindsFor()` em `verification_records.signature_status` (valores novos
 *    `participant_govbr` / `participant_external_unverified`);
 * 5. chamar `resetDocument()` quando a base for refeita e `closeFor()` quando o envelope
 *    concluir ou for encerrado.
 */
final class GovBrReturnStage
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function activeFor(Envelope $envelope): bool
    {
        return ExternalSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('status', ExternalSignatureRequestStatus::activeValues())
            ->exists();
    }

    /**
     * Ainda falta alguma devolução dentro do prazo? Vence o que passou do prazo total, devolve
     * a `requested` as reservas vencidas e os aceitos cuja revisão sumiu (base refeita).
     */
    public function awaiting(Envelope $envelope, ?string $correlationId = null): bool
    {
        $now = Carbon::now();
        $remaining = 0;

        $this->reconcileVanished($envelope);

        $rows = ExternalSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('status', ExternalSignatureRequestStatus::pendingValues())
            ->get();

        foreach ($rows as $row) {
            if ($row->window_expires_at === null) {
                $row->forceFill(['window_expires_at' => $now->copy()->addMinutes($this->windowMinutes())])->save();
            }

            if ($row->window_expires_at !== null && $row->window_expires_at->lte($now)) {
                $this->finish($row, ExternalSignatureRequestStatus::Expired, 'window_expired', 'O prazo para devolver o arquivo assinado no portal terminou.', $correlationId);

                continue;
            }

            if ($row->status === ExternalSignatureRequestStatus::Pending && ! $row->reservationActive($now)) {
                $this->releaseReservation($row, 'reservation_expired', 'A versão reservada venceu antes da devolução. Reserve e baixe de novo para assinar.');
            }

            $remaining++;
        }

        return $remaining > 0;
    }

    /**
     * Assinaturas devolvidas e aceitas que estão na cadeia deste documento.
     */
    public function signatureCount(Document $document): int
    {
        return $this->completedFor($document)->count();
    }

    /**
     * @return Collection<int, ExternalSignatureRequest>
     */
    public function completedFor(Document $document): Collection
    {
        return ExternalSignatureRequest::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->where('status', ExternalSignatureRequestStatus::Completed->value)
            ->whereNotNull('signed_document_version_id')
            ->orderBy('completed_at')
            ->get();
    }

    /**
     * Rótulos das devoluções aceitas no documento (para o `signature_status`).
     *
     * @return list<GovBrSignatureKind>
     */
    public function kindsFor(Document $document): array
    {
        $kinds = [];

        foreach ($this->completedFor($document) as $row) {
            if ($row->signature_kind instanceof GovBrSignatureKind) {
                $kinds[$row->signature_kind->value] = $row->signature_kind;
            }
        }

        return array_values($kinds);
    }

    /**
     * A reserva deixa de valer: o pedido volta a `requested` (o participante pode reservar de
     * novo) e a revisão reservada deixa de poder ser baixada.
     */
    public function releaseReservation(ExternalSignatureRequest $row, string $code, string $message): void
    {
        $row->forceFill([
            'status' => ExternalSignatureRequestStatus::Requested,
            'expected_document_version_id' => null,
            'expected_revision_sha256' => null,
            'expected_revision_size' => null,
            'reserved_at' => null,
            'expires_at' => null,
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();
    }

    /**
     * Base refeita (consolidação/evidências regeneradas): as devoluções aceitas sobre a base
     * antiga — nunca publicadas — deixam de descrever o arquivo; o participante assina de novo.
     */
    public function resetDocument(Document $document, string $reason = 'base_rebuilt'): int
    {
        $rows = ExternalSignatureRequest::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->whereIn('status', [ExternalSignatureRequestStatus::Completed->value, ExternalSignatureRequestStatus::Pending->value])
            ->get();

        foreach ($rows as $row) {
            $this->reopen($row, $reason);
        }

        return $rows->count();
    }

    /**
     * Envelope concluído, cancelado ou encerrado: o que ainda esperava não recebe mais nada.
     */
    public function closeFor(Envelope $envelope, string $code = 'envelope_closed', ?string $correlationId = null): int
    {
        $rows = ExternalSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('status', ExternalSignatureRequestStatus::pendingValues())
            ->get();

        foreach ($rows as $row) {
            $this->finish($row, ExternalSignatureRequestStatus::Closed, $code, 'O documento não está mais recebendo assinaturas pelo portal.', $correlationId);
        }

        return $rows->count();
    }

    public function windowMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.govbr.application_window_minutes', 4320));
    }

    private function reconcileVanished(Envelope $envelope): void
    {
        $rows = ExternalSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', ExternalSignatureRequestStatus::Completed->value)
            ->whereNull('signed_document_version_id')
            ->get();

        foreach ($rows as $row) {
            $this->reopen($row, 'base_rebuilt');
        }
    }

    private function reopen(ExternalSignatureRequest $row, string $reason): void
    {
        $row->forceFill([
            'status' => ExternalSignatureRequestStatus::Requested,
            'expected_document_version_id' => null,
            'expected_revision_sha256' => null,
            'expected_revision_size' => null,
            'reserved_at' => null,
            'expires_at' => null,
            'signed_document_version_id' => null,
            'signature_kind' => null,
            'trusted' => false,
            'completed_at' => null,
            'failure_code' => $reason,
            'failure_message' => 'O arquivo que recebe as assinaturas precisou ser refeito. Reserve, assine no portal e devolva a nova versão.',
        ])->save();

        $this->logger->warning('gov.br (devolução): pedido reaberto; a revisão aceita deixou de descrever o arquivo.', [
            'request_ulid' => $row->ulid,
            'reason' => $reason,
        ]);
    }

    private function finish(ExternalSignatureRequest $row, ExternalSignatureRequestStatus $status, string $code, string $message, ?string $correlationId): void
    {
        $row->forceFill([
            'status' => $status,
            'expected_document_version_id' => null,
            'expected_revision_sha256' => null,
            'expected_revision_size' => null,
            'expires_at' => null,
            'failure_code' => $code,
            'failure_message' => $message,
            'closed_at' => Carbon::now(),
        ])->save();

        $this->logger->info('gov.br (devolução): pedido encerrado.', [
            'request_ulid' => $row->ulid,
            'status' => $status->value,
            'reason' => $code,
            'correlation_id' => $correlationId,
        ]);
    }
}
