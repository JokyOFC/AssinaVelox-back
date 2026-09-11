<?php

namespace App\Services\Signing\Certificates;

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Pdf\Exceptions\PdfToolException;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Signing\Certificates\Exceptions\ParticipantCertificateException;
use App\Services\Signing\Certificates\Exceptions\ParticipantSignatureFailed;
use App\Services\Signing\Certificates\Exceptions\StaleRevisionException;
use App\Services\Signing\SignerAudit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aplica a assinatura de UM pedido de participante em todos os documentos do envelope
 * (roadmap §2.12, passos 3 a 7). Chamado pelo job `ApplyParticipantSignature`.
 *
 * 1. sob o lock do envelope ({@see EnvelopeSigningLock}) — um gravador por vez;
 * 2. confere que o envelope ainda recebe assinatura (em `finalizing`, sem `final`) e que a
 *    base congelada existe; sem base, devolve {@see self::WAITING_BASE} SEM abrir o material;
 * 3. abre o material cifrado (consumo único: o arquivo é apagado na abertura), grava o PFX
 *    num diretório temporário exclusivo;
 * 4. por documento, sobre a revisão MAIS RECENTE: `pdftool participant-sign` (assinatura
 *    incremental + validação de TODAS as assinaturas) e gravação compare-and-set
 *    ({@see IncrementalRevisions::storeSigned()}); se outra revisão entrou no meio, recalcula
 *    sobre ela — nunca grava revisão irmã;
 * 5. em `finally`: PFX e senha destruídos (diretório apagado, memória zerada), com sucesso
 *    ou falha.
 *
 * Nenhuma transação de banco fica aberta durante o pdftool. Idempotente: pedido já aplicado
 * devolve `applied`; documento já assinado por este pedido é pulado (retomada).
 */
final class ParticipantSignatureApplier
{
    public const FIELD_PREFIX = 'AV_Participante_';

    public const APPLIED = 'applied';

    public const WAITING_BASE = 'waiting_base';

    public const EXPIRED = 'expired';

    public const CLOSED = 'closed';

    public const SKIPPED = 'skipped';

    private const MAX_STALE_RETRIES = 3;

    public function __construct(
        private readonly ParticipantCertificateTool $tool,
        private readonly SealedCertificateStore $sealed,
        private readonly IncrementalRevisions $revisions,
        private readonly EnvelopeSigningLock $lock,
        private readonly DocumentStorage $storage,
        private readonly PdfToolClient $client,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Nome ÚNICO do campo de assinatura deste participante (um por participante e documento).
     */
    public static function fieldName(Recipient $recipient): string
    {
        return self::FIELD_PREFIX.$recipient->ulid;
    }

    /**
     * @return self::APPLIED|self::WAITING_BASE|self::EXPIRED|self::CLOSED|self::SKIPPED
     *
     * @throws LockTimeoutException outro gravador segura o envelope além da espera
     * @throws ParticipantSignatureFailed a aplicação falhou; o pedido foi marcado `failed`
     */
    public function apply(int $requestId, ?string $correlationId = null, ?int $lockWaitSeconds = null): string
    {
        $correlationId ??= (string) Str::ulid();
        $request = $this->find($requestId);

        if ($request === null) {
            return self::SKIPPED;
        }

        if ($request->status === ParticipantSignatureRequestStatus::Applied) {
            return self::APPLIED;
        }

        if (! in_array($request->status, [ParticipantSignatureRequestStatus::Queued, ParticipantSignatureRequestStatus::Applying], true)) {
            return self::SKIPPED;
        }

        return $this->lock->run(
            (int) $request->envelope_id,
            fn (): string => $this->applyLocked($requestId, $correlationId),
            $lockWaitSeconds,
        );
    }

    /**
     * Última tentativa do job esgotada: o pedido falha e o material é destruído.
     */
    public function abandon(int $requestId, string $code): void
    {
        $request = $this->find($requestId);

        if ($request === null || ! in_array($request->status, [ParticipantSignatureRequestStatus::Queued, ParticipantSignatureRequestStatus::Applying], true)) {
            return;
        }

        $envelope = Envelope::withoutOrganizationScope()->find($request->envelope_id);
        $recipient = Recipient::withoutOrganizationScope()->find($request->recipient_id);

        if ($envelope instanceof Envelope) {
            $this->fail($envelope, $request, $recipient, $code, 'A assinatura com o seu certificado não pôde ser aplicada. Envie o certificado de novo.', null);
        }
    }

    /**
     * @return self::APPLIED|self::WAITING_BASE|self::EXPIRED|self::CLOSED|self::SKIPPED
     */
    private function applyLocked(int $requestId, string $correlationId): string
    {
        $request = $this->find($requestId);

        if ($request === null || ! in_array($request->status, [ParticipantSignatureRequestStatus::Queued, ParticipantSignatureRequestStatus::Applying], true)) {
            return $request?->status === ParticipantSignatureRequestStatus::Applied ? self::APPLIED : self::SKIPPED;
        }

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->with('organization')->find($request->envelope_id);
        /** @var Recipient|null $recipient */
        $recipient = Recipient::withoutOrganizationScope()->find($request->recipient_id);

        if ($envelope === null || $recipient === null) {
            $this->sealed->discard($request->sealed_ulid);
            $request->forceFill(['status' => ParticipantSignatureRequestStatus::Expired, 'sealed_ulid' => null, 'closed_at' => Carbon::now()])->save();

            return self::CLOSED;
        }

        if ($envelope->status !== EnvelopeStatus::Finalizing) {
            $this->close($envelope, $request, $recipient, 'envelope_not_finalizing', 'O documento não está mais recebendo assinaturas com certificado.', $correlationId);

            return self::CLOSED;
        }

        $documents = EnvelopeDocuments::sent($envelope);

        if ($documents === [] || $this->hasFinal($documents)) {
            $this->close($envelope, $request, $recipient, 'already_finalized', 'O arquivo final já foi gerado; a assinatura com certificado não pôde ser acrescentada.', $correlationId);

            return self::CLOSED;
        }

        if (! $this->sealed->exists($request->sealed_ulid) || $request->sealed_expires_at === null || $request->sealed_expires_at->isPast()) {
            $this->fail($envelope, $request, $recipient, 'sealed_expired', 'O prazo para usar o certificado enviado terminou. Envie o certificado de novo.', $correlationId);

            return self::EXPIRED;
        }

        foreach ($documents as $row) {
            if (! $this->alreadySigned($request, $row['document']) && $this->revisions->latest($row['document']) === null) {
                // A base congelada ainda está sendo preparada pela finalização.
                return self::WAITING_BASE;
            }
        }

        $request->forceFill([
            'status' => ParticipantSignatureRequestStatus::Applying,
            'attempts' => $request->attempts + 1,
        ])->save();

        try {
            $material = $this->sealed->open((string) $request->sealed_ulid, $request->ulid);
        } catch (ParticipantCertificateException $exception) {
            $this->fail($envelope, $request, $recipient, $exception->errorCode, $exception->getMessage(), $correlationId);

            throw new ParticipantSignatureFailed($exception->errorCode, $exception->getMessage());
        }

        // O arquivo cifrado já não existe; o banco esquece o identificador.
        $request->forceFill(['sealed_ulid' => null, 'sealed_expires_at' => null])->save();

        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'a1-apply-');

        try {
            $pfxPath = $workDir->path('participante.pfx');
            $material->writePfxTo($pfxPath);

            /** @var int|null $acceptanceId */
            $acceptanceId = SignatureAcceptance::withoutOrganizationScope()
                ->where('recipient_id', $recipient->getKey())
                ->value('id');

            foreach ($documents as $row) {
                if ($this->alreadySigned($request, $row['document'])) {
                    continue;
                }

                $this->signDocument($envelope, $row['document'], $request, $recipient, $material->password(), $pfxPath, $acceptanceId, $workDir, $correlationId);
            }

            $request->forceFill([
                'status' => ParticipantSignatureRequestStatus::Applied,
                'applied_at' => Carbon::now(),
                'failure_code' => null,
                'failure_message' => null,
                'closed_at' => null,
            ])->save();

            return self::APPLIED;
        } catch (PdfToolException $exception) {
            $message = CertificateRejections::message($exception->errorCode);
            $this->fail($envelope, $request, $recipient, $exception->errorCode, $message, $correlationId);

            throw new ParticipantSignatureFailed($exception->errorCode, $message);
        } catch (ParticipantSignatureFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('A1 do participante: falha inesperada ao aplicar a assinatura.', [
                'request_ulid' => $request->ulid,
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);

            $code = $exception instanceof StaleRevisionException ? 'stale_revision' : 'application_failed';
            $message = 'A assinatura com o seu certificado não pôde ser aplicada. Envie o certificado de novo.';
            $this->fail($envelope, $request, $recipient, $code, $message, $correlationId);

            throw new ParticipantSignatureFailed($code, $message);
        } finally {
            $material->wipe();
            unset($material);
            $workDir->delete();
        }
    }

    /**
     * @throws StaleRevisionException depois de {@see self::MAX_STALE_RETRIES} tentativas
     * @throws PdfToolException
     */
    private function signDocument(
        Envelope $envelope,
        Document $document,
        ParticipantSignatureRequest $request,
        Recipient $recipient,
        #[\SensitiveParameter] string $password,
        string $pfxPath,
        ?int $acceptanceId,
        TemporaryDirectory $workDir,
        string $correlationId,
    ): void {
        for ($attempt = 1; $attempt <= self::MAX_STALE_RETRIES; $attempt++) {
            $base = $this->revisions->latest($document)
                ?? throw new StaleRevisionException('A revisão-base do documento não está disponível.');

            $input = $this->storage->copyToTemporary($base, $workDir, sprintf('base-%d-%d.pdf', $document->getKey(), $attempt));

            if (! hash_equals((string) $base->sha256, (string) hash_file('sha256', $input))) {
                throw new StaleRevisionException('Os bytes da revisão-base mudaram durante a leitura.');
            }

            $output = $workDir->path(sprintf('assinado-%d-%d.pdf', $document->getKey(), $attempt));

            $result = $this->tool->sign(
                $input,
                $output,
                $pfxPath,
                $password,
                self::fieldName($recipient),
                $request->fingerprint_sha256,
                ParticipantA1Feature::trustRoots(),
                (string) $this->config->get('assinavelox.participant_a1.reason', 'Assinatura com o certificado do participante'),
                $correlationId,
            );

            try {
                [$version, $signature] = $this->revisions->storeSigned(
                    $envelope,
                    $document,
                    $base,
                    $output,
                    fn (DocumentVersion $version): ParticipantSignature => $this->record($envelope, $document, $request, $recipient, $base, $version, $acceptanceId, $result),
                    $correlationId,
                );
            } catch (StaleRevisionException) {
                // Outra revisão entrou entre a leitura e a gravação: recalcula sobre ela.
                $this->logger->warning('A1 do participante: base desatualizada; recalculando sobre a revisão mais recente.', [
                    'request_ulid' => $request->ulid,
                    'document_ulid' => $document->ulid,
                    'attempt' => $attempt,
                    'correlation_id' => $correlationId,
                ]);

                continue;
            }

            SignerAudit::system($envelope, AuditEventType::ParticipantSignatureApplied, [
                'request_ulid' => $request->ulid,
                'document_ulid' => $document->ulid,
                'document_version_ulid' => $version->ulid,
                'sha256' => $version->sha256,
                'revision_index' => $signature->revision_index,
                'field_name' => $signature->field_name,
                'profile' => $signature->profile,
                'certificate_fingerprint_sha256' => $signature->fingerprint_sha256,
                'is_test_certificate' => $request->is_test_certificate,
                'signature_count' => (int) ($result['signature_count'] ?? 0),
                'chain_ok' => (bool) ($result['chain']['ok'] ?? false),
            ], $recipient, $correlationId);

            return;
        }

        throw new StaleRevisionException('Não foi possível gravar a assinatura sobre a revisão mais recente.');
    }

    /**
     * @param  array<string, mixed>  $result  saída de `pdftool participant-sign`
     */
    private function record(
        Envelope $envelope,
        Document $document,
        ParticipantSignatureRequest $request,
        Recipient $recipient,
        DocumentVersion $base,
        DocumentVersion $version,
        ?int $acceptanceId,
        array $result,
    ): ParticipantSignature {
        $validation = is_array($result['validation'] ?? null) ? $result['validation'] : [];
        $signatures = [];

        foreach ((array) ($validation['signatures'] ?? []) as $signature) {
            if (is_array($signature)) {
                $signatures[] = [
                    'field_name' => $signature['field_name'] ?? null,
                    'intact' => $signature['intact'] ?? null,
                    'valid' => $signature['valid'] ?? null,
                    'trusted' => $signature['trusted'] ?? null,
                    'coverage' => $signature['coverage'] ?? null,
                    'modification_level' => $signature['modification_level'] ?? null,
                    'cert_fingerprint_sha256' => $signature['cert_fingerprint_sha256'] ?? null,
                ];
            }
        }

        $signature = new ParticipantSignature;
        $signature->forceFill([
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'participant_signature_request_id' => $request->getKey(),
            'recipient_id' => $recipient->getKey(),
            'document_id' => $document->getKey(),
            'signature_acceptance_id' => $acceptanceId,
            'certificate_reference_id' => null,
            'base_document_version_id' => $base->getKey(),
            'signed_document_version_id' => $version->getKey(),
            'revision_index' => $this->revisions->signedRevisions($document)->count(),
            'field_name' => (string) ($result['field_name'] ?? self::fieldName($recipient)),
            'profile' => (string) ($result['profile'] ?? 'PAdES-B-B'),
            'subject' => CertificateInspection::maskCpfIn(is_string($result['signer_subject'] ?? null) ? $result['signer_subject'] : null),
            'issuer' => is_string($result['issuer'] ?? null) ? $result['issuer'] : null,
            'serial_number' => is_string($result['serial_hex'] ?? null) ? $result['serial_hex'] : null,
            'fingerprint_sha256' => is_string($result['cert_fingerprint_sha256'] ?? null) ? $result['cert_fingerprint_sha256'] : null,
            'not_before' => is_string($result['not_before'] ?? null) ? Carbon::parse($result['not_before']) : null,
            'not_after' => is_string($result['not_after'] ?? null) ? Carbon::parse($result['not_after']) : null,
            // Resultado da validação de TODAS as assinaturas desta revisão (sem dados pessoais).
            'validation_result' => [
                'chain' => $result['chain'] ?? null,
                'previous_signature_count' => $result['previous_signature_count'] ?? null,
                'signature_count' => $result['signature_count'] ?? null,
                'prefix_preserved' => $result['prefix_preserved'] ?? null,
                'revocation' => $validation['revocation'] ?? 'not_checked',
                'trust_roots_configured' => $validation['trust_roots_configured'] ?? 0,
                'signatures' => $signatures,
            ],
            'signed_at' => Carbon::now(),
        ]);
        $signature->save();

        return $signature;
    }

    /**
     * @param  list<array{document: Document, version: DocumentVersion}>  $documents
     */
    private function hasFinal(array $documents): bool
    {
        $ids = array_map(static fn (array $row): int => (int) $row['document']->getKey(), $documents);

        return DocumentVersion::withoutOrganizationScope()
            ->whereIn('document_id', $ids)
            ->where('kind', DocumentVersionKind::Final->value)
            ->exists();
    }

    private function alreadySigned(ParticipantSignatureRequest $request, Document $document): bool
    {
        return ParticipantSignature::withoutOrganizationScope()
            ->where('participant_signature_request_id', $request->getKey())
            ->where('document_id', $document->getKey())
            ->exists();
    }

    private function find(int $requestId): ?ParticipantSignatureRequest
    {
        /** @var ParticipantSignatureRequest|null $request */
        $request = ParticipantSignatureRequest::withoutOrganizationScope()->find($requestId);

        return $request;
    }

    private function close(Envelope $envelope, ParticipantSignatureRequest $request, ?Recipient $recipient, string $code, string $message, ?string $correlationId): void
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
        ], $recipient, $correlationId);
    }

    private function fail(Envelope $envelope, ParticipantSignatureRequest $request, ?Recipient $recipient, string $code, string $message, ?string $correlationId): void
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
        ], $recipient, $correlationId);
    }
}
