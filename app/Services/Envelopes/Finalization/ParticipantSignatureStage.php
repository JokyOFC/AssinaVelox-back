<?php

namespace App\Services\Envelopes\Finalization;

use App\Enums\AuditEventType;
use App\Enums\CertificateEnvironment;
use App\Enums\DocumentVersionKind;
use App\Enums\ExternalSignatureKind;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Enums\SignatureStatus;
use App\Jobs\Envelopes\ApplyParticipantSignatureDeadline;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Signing\Certificates\CertificateInspection;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Signing\Certificates\IncrementalChain;
use App\Services\Signing\Certificates\IncrementalRevisions;
use App\Services\Signing\Certificates\ParticipantA1Feature;
use App\Services\Signing\Certificates\SealedCertificateStore;
use App\Services\Signing\GovBr\ExternalSignatureRequestStatus;
use App\Services\Signing\GovBr\GovBrReturnStage;
use App\Services\Signing\GovBr\GovBrSignatureKind;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;
use App\Services\Signing\SignerAudit;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Encaixe das assinaturas com o certificado dos participantes na finalização (Fase 2 §2.12).
 *
 * O {@see EnvelopeFinalizer} só entra neste caminho quando o envelope tem pedidos ativos
 * ({@see self::activeFor()}); sem pedidos, o pipeline é o da Fase 1, byte a byte.
 *
 * Responsabilidades daqui:
 *
 * - **base congelada** (`pre_signature` = consolidado + evidências), o que "não pode mais
 *   mudar" quando os participantes assinam;
 * - **espera** pelos pedidos pendentes, com prazo: o que passa do prazo vence
 *   (`participant_signature.expired`) e a finalização segue sem aquela assinatura — o aceite
 *   eletrônico já registrado continua valendo (roadmap §2.12, riscos);
 * - **validação da cadeia** inteira do arquivo final e o `validation_result` publicado;
 * - **reinício honesto**: se a base precisar ser refeita, as assinaturas calculadas sobre a
 *   antiga são descartadas (nunca publicadas) e os pedidos voltam a "aguardando certificado".
 *
 * Fase 3 §3.5 (integração I-3A, docs/fase-3/gov-br.md §8): as devoluções do PDF assinado no
 * portal gov.br ({@see GovBrReturnStage}) entram no MESMO caminho — contam como pedido ativo,
 * seguram a finalização enquanto esperam, somam na quantidade esperada de assinaturas da cadeia,
 * entram no `signature_status` e são reabertas quando a base é refeita. Sem pedido gov.br (o caso
 * com a flag `govbr_return` desligada), nada disso muda o comportamento.
 */
final class ParticipantSignatureStage
{
    public function __construct(
        private readonly FinalizationArtifacts $artifacts,
        private readonly IncrementalRevisions $revisions,
        private readonly PdfToolClient $client,
        private readonly EnvelopeSigningLock $lock,
        private readonly SealedCertificateStore $sealed,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly GovBrReturnStage $govbr,
    ) {}

    public function activeFor(Envelope $envelope): bool
    {
        return ParticipantSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('status', ParticipantSignatureRequestStatus::activeValues())
            ->exists()
            // Fase 3 §3.5 (I-3A): devolução gov.br pedida, reservada ou aceita.
            || $this->govbr->activeFor($envelope);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function locked(Envelope $envelope, callable $callback): mixed
    {
        return $this->lock->run((int) $envelope->getKey(), $callback);
    }

    /**
     * Base congelada do documento: reaproveitada, ou montada (append do consolidado com a
     * página de evidências) e gravada como `pre_signature`.
     *
     * @return 'created'|'reused'
     *
     * @throws FinalizationException
     */
    public function ensureBase(
        Envelope $envelope,
        Document $document,
        DocumentVersion $consolidated,
        DocumentVersion $evidence,
        TemporaryDirectory $workDir,
        string $correlationId,
        bool $rebuilt,
    ): string {
        $base = $this->artifacts->existing($document, DocumentVersionKind::PreSignature);

        if ($base !== null && ! $rebuilt) {
            return 'reused';
        }

        // Base refeita (ou perdida): o que foi assinado sobre a antiga não descreve mais o arquivo.
        if ($base !== null || $this->revisions->signedRevisions($document)->isNotEmpty()) {
            $this->resetDocument($envelope, $document, $rebuilt ? 'base_rebuilt' : 'base_missing', $correlationId);
        }

        $baseFile = $this->artifacts->copyToTemporary($consolidated, $workDir, 'base.pdf');
        $extra = $this->artifacts->copyToTemporary($evidence, $workDir, 'evidencias-anexo.pdf');
        $output = $workDir->path('pre-assinatura.pdf');

        $this->client->append($baseFile, $extra, $output, $correlationId);

        $this->artifacts->store($envelope, $document, DocumentVersionKind::PreSignature, $output, $correlationId);

        return 'created';
    }

    /**
     * Ainda falta alguma assinatura de participante, dentro do prazo? Vence (e registra) os
     * pedidos que passaram do prazo e os que ficaram presos (material vencido, worker morto).
     *
     * Fase 3 §3.5 (I-3A): inclui as devoluções gov.br pendentes. As duas conferências rodam
     * sempre (cada uma vence os próprios prazos), sem curto-circuito.
     */
    public function awaiting(Envelope $envelope, string $correlationId): bool
    {
        $certificates = $this->awaitingCertificates($envelope, $correlationId);
        $govbr = $this->govbr->awaiting($envelope, $correlationId);

        if ($govbr && ! $certificates) {
            $pending = ExternalSignatureRequest::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->whereIn('status', ExternalSignatureRequestStatus::pendingValues())
                ->count();

            $this->announceWaiting($envelope, max(1, $pending), null, false, $correlationId);
        }

        return $certificates || $govbr;
    }

    private function awaitingCertificates(Envelope $envelope, string $correlationId): bool
    {
        $pending = ParticipantSignatureRequest::withoutOrganizationScope()
            ->with(['recipient' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('status', ParticipantSignatureRequestStatus::pendingValues())
            ->get();

        if ($pending->isEmpty()) {
            return false;
        }

        $now = Carbon::now();
        $window = max(1, (int) $this->config->get('assinavelox.participant_a1.application_window_minutes', 4320));
        $lockSeconds = max(30, (int) $this->config->get('assinavelox.participant_a1.lock_seconds', 900));
        $remaining = 0;
        $earliest = null;
        $windowOpened = false;

        foreach ($pending as $request) {
            if ($request->window_expires_at === null) {
                $request->forceFill(['window_expires_at' => $now->copy()->addMinutes($window)])->save();
                $windowOpened = true;
            }

            if ($request->window_expires_at !== null && $request->window_expires_at->lte($now)) {
                $this->expire($envelope, $request, 'window_expired', 'O prazo para assinar com o próprio certificado terminou.', $correlationId);

                continue;
            }

            // Material cifrado vencido sem worker: o participante precisa reenviar.
            if ($request->status === ParticipantSignatureRequestStatus::Queued
                && $request->sealed_expires_at !== null
                && $request->sealed_expires_at->lt($now)) {
                $this->markFailed($envelope, $request, 'sealed_expired', 'O prazo para usar o certificado enviado terminou antes da aplicação. Envie o certificado de novo.', $correlationId);
            }

            // Aplicação interrompida (worker morreu com o lock vencido).
            if ($request->status === ParticipantSignatureRequestStatus::Applying
                && $request->updated_at !== null
                && $request->updated_at->lt($now->copy()->subSeconds($lockSeconds))) {
                $this->markFailed($envelope, $request, 'application_interrupted', 'A aplicação da assinatura foi interrompida. Envie o certificado de novo.', $correlationId);
            }

            $remaining++;
            $earliest = $earliest === null || $request->window_expires_at->lt($earliest) ? $request->window_expires_at : $earliest;
        }

        if ($remaining === 0) {
            return false;
        }

        $this->announceWaiting($envelope, $remaining, $earliest, $windowOpened, $correlationId);

        return true;
    }

    /**
     * Prazo mais próximo entre os pedidos pendentes do envelope.
     */
    public function nextDeadline(int $envelopeId): ?CarbonInterface
    {
        $value = ParticipantSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelopeId)
            ->whereIn('status', ParticipantSignatureRequestStatus::pendingValues())
            ->whereNotNull('window_expires_at')
            ->min('window_expires_at');

        return $value === null ? null : Carbon::parse((string) $value);
    }

    /**
     * @throws FinalizationException
     */
    public function latestRevision(Document $document): DocumentVersion
    {
        return $this->revisions->latest($document)
            ?? throw FinalizationException::writeFailed('pre_signature', ['reason' => 'base_missing']);
    }

    /**
     * Assinaturas de participante na cadeia do documento: A1/componente local
     * (`participant_signatures`) + devoluções gov.br aceitas (Fase 3 §3.5, I-3A).
     */
    public function signatureCount(Document $document): int
    {
        return ParticipantSignature::withoutOrganizationScope()->where('document_id', $document->getKey())->count()
            + $this->govbr->signatureCount($document);
    }

    public function appliedRequestCount(Envelope $envelope): int
    {
        return ParticipantSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', ParticipantSignatureRequestStatus::Applied->value)
            ->count();
    }

    /**
     * Fase 3 §3.4 (P3-EXT): como {@see self::statusFor()}, mas com o MEIO de cada assinatura do
     * documento (T1). Sem assinatura feita fora da plataforma, o resultado é exatamente o de
     * `statusFor()` — o pipeline da Fase 2 não muda. Com ao menos uma, o valor é
     * `participant_external` (qualquer uma sem origem em token comprovada, inclusive o
     * simulador) ou `participant_a3` (todas de componente real, certificado A3).
     */
    public function statusForDocument(Document $document, bool $operator, int $participantSignatures): SignatureStatus
    {
        $status = $this->statusFor($operator, $participantSignatures);

        if ($participantSignatures === 0) {
            return $status;
        }

        $kinds = ParticipantSignature::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->whereNotNull('signature_status')
            ->pluck('signature_status')
            ->map(static fn ($value): string => (string) $value)
            ->unique()
            ->values()
            ->all();

        // Fase 3 §3.5 (I-3A): devoluções gov.br aceitas. Só gov.br ⇒ `participant_govbr` quando
        // TODAS têm a cadeia validada até a âncora fixada, senão `participant_external_unverified`
        // (nunca dito gov.br). Junto com componente local ⇒ `participant_external`, o valor mais
        // genérico ("feita fora da plataforma"); a lista por assinatura traz o meio de cada uma.
        $govbr = array_values(array_unique(array_map(
            static fn (GovBrSignatureKind $kind): string => $kind->value,
            $this->govbr->kindsFor($document),
        )));

        if ($govbr !== []) {
            if ($kinds !== []) {
                return SignatureStatus::ParticipantExternal;
            }

            return $govbr === [GovBrSignatureKind::ParticipantGovBr->value]
                ? SignatureStatus::ParticipantGovBr
                : SignatureStatus::ParticipantExternalUnverified;
        }

        if ($kinds === []) {
            return $status;
        }

        return in_array(ExternalSignatureKind::ParticipantA3->value, $kinds, true) && count($kinds) === 1
            ? SignatureStatus::ParticipantA3
            : SignatureStatus::ParticipantExternal;
    }

    public function statusFor(bool $operator, int $participantSignatures): SignatureStatus
    {
        if ($participantSignatures > 0) {
            return $operator ? SignatureStatus::Mixed : SignatureStatus::ParticipantsA1;
        }

        return $operator ? SignatureStatus::CompanyA1 : SignatureStatus::None;
    }

    /**
     * O `final` local é a revisão `$latest` seguida só do que se espera (a assinatura da
     * operadora, quando houver), com exatamente `$expected` assinaturas?
     */
    public function finalMatches(string $localFinal, DocumentVersion $latest, int $expected, string $correlationId): bool
    {
        try {
            $inspection = $this->client->inspect($localFinal, $correlationId);
        } catch (Throwable) {
            return false;
        }

        if ($inspection->signatureCount !== $expected) {
            return false;
        }

        $size = (int) $latest->size_bytes;
        $handle = @fopen($localFinal, 'rb');

        if ($handle === false || $size <= 0) {
            return false;
        }

        try {
            $context = hash_init('sha256');
            $remaining = $size;

            while ($remaining > 0 && ! feof($handle)) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                hash_update($context, $chunk);
                $remaining -= strlen($chunk);
            }

            return $remaining === 0 && hash_equals((string) $latest->sha256, hash_final($context));
        } finally {
            fclose($handle);
        }
    }

    public function validate(string $path, ?string $correlationId = null): ValidationResult
    {
        return $this->client->validate($path, ParticipantA1Feature::trustRoots(), $correlationId);
    }

    /**
     * `verification_records.validation_result` no modo de participantes. Mesmo formato do
     * da operadora ({@see OperatorSignature::validationPayload()}), mais `incremental_chain`
     * — a análise que autoriza afirmar integridade de uma cadeia de revisões. CPF que
     * aparecer em nomes de certificado (convenção `NOME:CPF` do e-CPF) sai mascarado.
     *
     * @return array<string, mixed>
     */
    public function validationPayload(
        SignatureStatus $status,
        ?ValidationResult $validation,
        ?string $profile,
        ?CertificateEnvironment $operatorEnvironment,
    ): array {
        if ($validation === null) {
            return [
                'signed' => false,
                'profile' => null,
                'environment' => null,
                'validated_at' => Carbon::now()->utc()->toIso8601String(),
                'timestamp' => null,
                'long_term_validation' => false,
                'revocation' => 'not_checked',
                'reason' => 'no_signature_applied',
                'result' => null,
            ];
        }

        $summary = CertificateInspection::maskSignatureSummary($validation->summary());

        return [
            'signed' => $status !== SignatureStatus::None,
            'profile' => $profile,
            'environment' => $operatorEnvironment?->value,
            'validated_at' => Carbon::now()->utc()->toIso8601String(),
            'timestamp' => null,
            'long_term_validation' => false,
            'revocation' => $validation->revocation,
            'reason' => null,
            'result' => $summary,
            'incremental_chain' => IncrementalChain::analyse($validation),
        ];
    }

    private function announceWaiting(Envelope $envelope, int $remaining, ?CarbonInterface $earliest, bool $windowOpened, string $correlationId): void
    {
        $already = AuditEvent::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('event_type', AuditEventType::EnvelopeAwaitingParticipantSignatures->value)
            ->exists();

        if (! $already) {
            SignerAudit::system($envelope, AuditEventType::EnvelopeAwaitingParticipantSignatures, [
                'pending' => $remaining,
                'window_expires_at' => $earliest?->utc()->toIso8601String(),
            ], null, $correlationId);
        }

        // Retomada automática no fim do prazo (fila real). Com o driver `sync` o prazo é
        // conferido na próxima vez que a finalização rodar (outro envio, desistência, comando).
        if ($windowOpened && $earliest !== null && $this->queueIsAsynchronous()) {
            try {
                ApplyParticipantSignatureDeadline::dispatch((int) $envelope->getKey(), (int) $envelope->organization_id)
                    ->delay($earliest->copy()->addSeconds(5));
            } catch (Throwable $exception) {
                $this->logger->warning('A1 do participante: não foi possível agendar a retomada no fim do prazo.', [
                    'envelope_ulid' => $envelope->ulid,
                    'exception' => $exception::class,
                    'correlation_id' => $correlationId,
                ]);
            }
        }
    }

    private function queueIsAsynchronous(): bool
    {
        $connection = (string) $this->config->get('queue.default', 'sync');

        return (string) $this->config->get('queue.connections.'.$connection.'.driver', 'sync') !== 'sync';
    }

    private function expire(Envelope $envelope, ParticipantSignatureRequest $request, string $code, string $message, string $correlationId): void
    {
        $this->sealed->discard($request->sealed_ulid);

        $request->forceFill([
            'status' => ParticipantSignatureRequestStatus::Expired,
            'sealed_ulid' => null,
            'sealed_expires_at' => null,
            'failure_code' => $code,
            'failure_message' => $message,
            'closed_at' => Carbon::now(),
        ])->save();

        SignerAudit::system($envelope, AuditEventType::ParticipantSignatureExpired, [
            'request_ulid' => $request->ulid,
            'reason' => $code,
        ], $request->recipient, $correlationId);
    }

    private function markFailed(Envelope $envelope, ParticipantSignatureRequest $request, string $code, string $message, string $correlationId): void
    {
        $this->sealed->discard($request->sealed_ulid);

        $request->forceFill([
            'status' => ParticipantSignatureRequestStatus::Failed,
            'sealed_ulid' => null,
            'sealed_expires_at' => null,
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();

        SignerAudit::system($envelope, AuditEventType::ParticipantSignatureFailed, [
            'request_ulid' => $request->ulid,
            'error_code' => $code,
        ], $request->recipient, $correlationId);
    }

    /**
     * Descarta base e revisões assinadas do documento e devolve os pedidos aplicados a
     * "aguardando certificado" — a plataforma não tem o PFX para refazer a assinatura.
     */
    private function resetDocument(Envelope $envelope, Document $document, string $reason, string $correlationId): void
    {
        $discarded = $this->revisions->discardAll($document, $reason);
        // Fase 3 §3.5 (I-3A): devoluções gov.br aceitas/reservadas sobre a base antiga reabrem.
        $this->govbr->resetDocument($document, $reason);

        $requests = ParticipantSignatureRequest::withoutOrganizationScope()
            ->with(['recipient' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('envelope_id', $envelope->getKey())
            ->where('status', ParticipantSignatureRequestStatus::Applied->value)
            ->get();

        foreach ($requests as $request) {
            $request->forceFill([
                'status' => ParticipantSignatureRequestStatus::Requested,
                'applied_at' => null,
                'window_expires_at' => null,
                'failure_code' => $reason,
                'failure_message' => 'O arquivo que recebe as assinaturas precisou ser refeito. Envie o certificado de novo para assinar a nova versão.',
            ])->save();

            SignerAudit::system($envelope, AuditEventType::ParticipantSignatureFailed, [
                'request_ulid' => $request->ulid,
                'error_code' => $reason,
                'document_ulid' => $document->ulid,
            ], $request->recipient, $correlationId);
        }

        $this->logger->warning('A1 do participante: base refeita; assinaturas nunca publicadas foram descartadas.', [
            'envelope_ulid' => $envelope->ulid,
            'document_ulid' => $document->ulid,
            'versions_discarded' => $discarded,
            'requests_reset' => $requests->count(),
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }
}
