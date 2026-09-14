<?php

namespace App\Services\Signing\GovBr;

use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SigningFieldValue;
use App\Services\Documents\DocumentStorage;
use App\Services\Pdf\Exceptions\PdfToolException;
use App\Services\Pdf\Exceptions\PdfToolInputRejectedException;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Signing\Certificates\Exceptions\StaleRevisionException;
use App\Services\Signing\GovBr\Exceptions\GovBrReturnException;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;
use App\Services\Signing\GovBr\Models\ExternalSignatureReturn;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerDownloadGrants;
use App\Services\Signing\SignerSessions;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Fluxo "assinar no portal gov.br e devolver o PDF" (Fase 3 §3.5, fluxo alternativo; P3-GOV;
 * docs/fase-3/gov-br.md).
 *
 * 1. **intenção** — o participante escolhe o caminho; nada é reservado ainda;
 * 2. **reserva** — com o documento pronto (base congelada da finalização), a revisão MAIS
 *    RECENTE de cada documento é reservada: `expected_revision_sha256`, tamanho e prazo. Um
 *    participante por vez por documento (a revisão seguinte se empilha sobre a devolvida);
 * 3. **download** — os bytes exatos reservados (conferidos contra o resumo antes de sair);
 * 4. **devolução** — o servidor aceita SOMENTE o arquivo que estende a revisão reservada com
 *    exatamente uma assinatura nova e nada mais ({@see GovBrReturnDecision}); o aceito entra
 *    na cadeia como `signed_incremental` por compare-and-set ({@see GovBrRevisionStore}),
 *    sob o lock do envelope. Toda devolução (aceita ou recusada) fica em
 *    `external_signature_returns`.
 *
 * Autenticação: a mesma do A1 do participante — sessão do código deste participante neste
 * navegador, ou a janela de download aberta pelo aceite/novo código. O link sozinho não basta.
 */
final class GovBrReturnService
{
    public function __construct(
        private readonly GovBrReturnTool $tool,
        private readonly GovBrTrustAnchors $anchors,
        private readonly GovBrRevisionStore $store,
        private readonly GovBrReturnStage $stage,
        private readonly EnvelopeSigningLock $lock,
        private readonly DocumentStorage $storage,
        private readonly SignerSessions $sessions,
        private readonly SignerDownloadGrants $grants,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function offeredTo(SignerContext $context): bool
    {
        return GovBrReturnFeature::enabledFor($context->organization)
            && GovBrReturnFeature::allowsRecipient($context->recipient)
            && in_array($context->state, [
                SignerContext::STATE_ACTIVE,
                SignerContext::STATE_SIGNED_PENDING_OTHERS,
                SignerContext::STATE_FINALIZING,
                SignerContext::STATE_COMPLETED,
            ], true);
    }

    public function authenticated(SignerContext $context, Request $request): bool
    {
        if ($this->sessions->current($context, $request) !== null) {
            return true;
        }

        return $context->hasSigned() && $this->grants->current($context, $request) !== null;
    }

    /**
     * Estado para a página pública (contrato em docs/fase-3/gov-br.md §6).
     *
     * @return array<string, mixed>
     */
    public function state(SignerContext $context, Request $request): array
    {
        $authenticated = $this->authenticated($context, $request);
        $open = $this->acceptsIntent($context);
        $ready = $this->ready($context);
        $rows = $this->rows($context);
        $documents = [];
        $position = 0;

        foreach ($context->sentDocuments() as $item) {
            $position++;
            $row = $rows->get((int) $item['document']->getKey());
            $documents[] = [
                'document' => [
                    'id' => $item['document']->ulid,
                    'name' => (string) $item['document']->name,
                    'position' => $position,
                ],
                'request' => $row === null ? null : $this->requestProps($context, $row, $authenticated),
            ];
        }

        $statuses = $rows->map(fn (ExternalSignatureRequest $row): ExternalSignatureRequestStatus => $row->status)->values()->all();
        $has = static fn (ExternalSignatureRequestStatus $status): bool => in_array($status, $statuses, true);
        $completedAll = $statuses !== [] && count(array_filter($statuses, static fn ($status): bool => $status === ExternalSignatureRequestStatus::Completed)) === count($context->sentDocuments());
        $reservable = $open && $ready && ($statuses === [] || $has(ExternalSignatureRequestStatus::Requested) || $has(ExternalSignatureRequestStatus::Pending));

        $stage = match (true) {
            $completedAll => 'completed',
            $rows->contains(fn (ExternalSignatureRequest $row): bool => $row->reservationActive()) => 'reserved',
            $has(ExternalSignatureRequestStatus::Requested) || $has(ExternalSignatureRequestStatus::Pending) => $ready ? 'ready_to_reserve' : 'awaiting_others',
            $has(ExternalSignatureRequestStatus::Expired) => 'expired',
            $has(ExternalSignatureRequestStatus::Withdrawn) && $open => $ready ? 'ready_to_reserve' : 'choose',
            $has(ExternalSignatureRequestStatus::Closed) => 'closed',
            $statuses === [] && $open => $ready ? 'ready_to_reserve' : 'choose',
            default => 'closed',
        };

        $anchors = $this->anchors->configured();
        $ttl = $this->reservationMinutes();

        return [
            'available' => true,
            'authenticated' => $authenticated,
            'stage' => $stage,
            'ready' => $ready,
            'message' => $ready ? null : ($open ? 'O documento fica pronto para assinar no portal quando todos os participantes concluírem o aceite.' : 'Este documento não está mais recebendo assinaturas pelo portal.'),
            'can_request' => $authenticated && $open && ($statuses === [] || $has(ExternalSignatureRequestStatus::Withdrawn)),
            'can_withdraw' => $authenticated && ($has(ExternalSignatureRequestStatus::Requested) || $has(ExternalSignatureRequestStatus::Pending)),
            'can_reserve' => $authenticated && $reservable,
            'can_upload' => $authenticated && $rows->contains(fn (ExternalSignatureRequest $row): bool => $row->reservationActive()),
            'trust' => [
                'anchors_configured' => $anchors,
                'accepted_kind' => GovBrSignatureKind::for($anchors, true)->value,
                'accepted_label' => GovBrSignatureKind::for($anchors, true)->label(),
                'revocation_checked' => false,
            ],
            'documents' => $documents,
            'instructions' => [
                sprintf('Reserve a versão para assinar e baixe o arquivo de cada documento. A versão fica reservada por %d minutos.', $ttl),
                sprintf('Acesse %s com a sua conta gov.br (nível prata ou ouro) e assine o arquivo baixado, sem editar, converter ou salvar em outro programa.', $this->portalHost()),
                'Baixe o arquivo assinado que o portal devolver e envie-o aqui, sem abri-lo e salvá-lo de novo.',
                'A plataforma confere se o arquivo é exatamente a versão entregue com uma única assinatura acrescentada. Qualquer outra alteração faz o arquivo ser recusado.',
            ],
            'limits' => [
                'max_upload_mb' => $this->maxUploadMb(),
                'accepted_extensions' => ['pdf'],
                'reservation_ttl_minutes' => $ttl,
                'application_window_minutes' => $this->stage->windowMinutes(),
            ],
            'notices' => [
                'A assinatura feita no portal se soma ao seu aceite eletrônico e não o substitui.',
                $anchors
                    ? 'A cadeia do certificado é conferida contra a raiz gov.br fixada nesta plataforma. A revogação do certificado não é consultada.'
                    : 'Esta plataforma ainda não verifica a cadeia de certificados do gov.br: o arquivo aceito será registrado como “Assinatura digital de terceiro, cadeia não verificada”, e não como assinatura gov.br.',
                sprintf('Qualquer pessoa pode conferir o arquivo no validador oficial (%s); a plataforma não consulta esse validador automaticamente.', $this->validatorHost()),
            ],
            'portal_url' => (string) $this->config->get('assinavelox.govbr.portal_url', 'https://assinador.iti.br'),
            'endpoints' => [
                'show' => route('sign.govbr.show', ['token' => $context->token]),
                'intent' => route('sign.govbr.intent', ['token' => $context->token]),
                'withdraw' => route('sign.govbr.withdraw', ['token' => $context->token]),
                'reserve' => route('sign.govbr.reserve', ['token' => $context->token]),
            ],
        ];
    }

    /**
     * Registra a escolha "vou assinar no portal gov.br" para cada documento. Idempotente.
     *
     * @throws GovBrReturnException
     */
    public function requestIntent(SignerContext $context, Request $request): void
    {
        $this->assertAuthenticated($context, $request);

        if (! $this->acceptsIntent($context)) {
            throw new GovBrReturnException('envelope_closed', 'Este documento não está mais recebendo assinaturas pelo portal.', 409, 'intent');
        }

        $rows = $this->rows($context);

        foreach ($context->sentDocuments() as $item) {
            $document = $item['document'];
            $row = $rows->get((int) $document->getKey());

            if ($row !== null && in_array($row->status, [ExternalSignatureRequestStatus::Expired, ExternalSignatureRequestStatus::Closed], true)) {
                throw new GovBrReturnException('window_closed', 'O prazo para assinar no portal neste documento já terminou.', 409, 'intent');
            }

            if ($row !== null && $row->status !== ExternalSignatureRequestStatus::Withdrawn) {
                continue;
            }

            $row ??= new ExternalSignatureRequest;
            $row->forceFill([
                'organization_id' => $context->envelope->organization_id,
                'envelope_id' => $context->envelope->getKey(),
                'recipient_id' => $context->recipient->getKey(),
                'document_id' => $document->getKey(),
                'provider' => ExternalSignatureRequest::PROVIDER_GOVBR_PORTAL,
                'status' => ExternalSignatureRequestStatus::Requested,
                'failure_code' => null,
                'failure_message' => null,
                'closed_at' => null,
            ])->save();
        }

        $this->logger->info('gov.br (devolução): participante optou por assinar no portal.', [
            'envelope_ulid' => $context->envelope->ulid,
            'recipient_ulid' => $context->recipient->ulid,
        ]);
    }

    /**
     * Desiste antes de a devolução ser aceita. A finalização é retomada se esperava por isso.
     *
     * @throws GovBrReturnException
     */
    public function withdraw(SignerContext $context, Request $request): void
    {
        $this->assertAuthenticated($context, $request);

        $rows = $this->rows($context)->filter(fn (ExternalSignatureRequest $row): bool => in_array($row->status, [
            ExternalSignatureRequestStatus::Requested,
            ExternalSignatureRequestStatus::Pending,
        ], true));

        if ($rows->isEmpty()) {
            throw new GovBrReturnException('cannot_withdraw', 'Não há pedido de assinatura no portal que possa ser desfeito.', 409, 'intent');
        }

        foreach ($rows as $row) {
            $row->forceFill([
                'status' => ExternalSignatureRequestStatus::Withdrawn,
                'expected_document_version_id' => null,
                'expected_revision_sha256' => null,
                'expected_revision_size' => null,
                'reserved_at' => null,
                'expires_at' => null,
                'closed_at' => Carbon::now(),
            ])->save();
        }

        $this->resumeFinalization($context);
    }

    /**
     * Reserva a revisão mais recente de cada documento para este participante.
     *
     * @throws GovBrReturnException
     */
    public function reserve(SignerContext $context, Request $request): void
    {
        $this->assertAuthenticated($context, $request);

        if (! $this->acceptsIntent($context)) {
            throw new GovBrReturnException('envelope_closed', 'Este documento não está mais recebendo assinaturas pelo portal.', 409, 'reserve');
        }

        if (! $this->ready($context)) {
            throw new GovBrReturnException('not_ready', 'O documento fica pronto para assinar no portal quando todos os participantes concluírem o aceite.', 409, 'reserve');
        }

        $this->requestIntent($context, $request);

        $now = Carbon::now();
        $ttl = $this->reservationMinutes();

        foreach ($context->sentDocuments() as $item) {
            $document = $item['document'];
            $row = $this->rowFor($context, $document);

            if ($row === null || ! in_array($row->status, [ExternalSignatureRequestStatus::Requested, ExternalSignatureRequestStatus::Pending], true)) {
                continue;
            }

            if ($row->window_expires_at !== null && $row->window_expires_at->lte($now)) {
                throw new GovBrReturnException('window_closed', 'O prazo para assinar no portal neste documento já terminou.', 409, 'reserve');
            }

            $base = $this->store->latest($document);

            if ($base === null) {
                throw new GovBrReturnException('not_ready', 'O documento ainda está sendo preparado para receber assinaturas. Tente de novo em instantes.', 409, 'reserve');
            }

            if ($row->reservationActive($now) && (int) $row->expected_document_version_id === (int) $base->getKey()) {
                continue; // reserva vigente sobre a revisão atual: nada muda (idempotente)
            }

            $busy = ExternalSignatureRequest::withoutOrganizationScope()
                ->where('document_id', $document->getKey())
                ->where('recipient_id', '!=', $context->recipient->getKey())
                ->where('status', ExternalSignatureRequestStatus::Pending->value)
                ->where('expires_at', '>', $now)
                ->orderBy('expires_at')
                ->first();

            if ($busy !== null) {
                throw new GovBrReturnException(
                    'reservation_busy',
                    sprintf('Outro participante está assinando este documento no portal agora. Tente de novo depois de %s.', $busy->expires_at?->timezone((string) config('app.timezone', 'UTC'))->format('H:i')),
                    409,
                    'reserve',
                );
            }

            $row->forceFill([
                'status' => ExternalSignatureRequestStatus::Pending,
                'expected_document_version_id' => $base->getKey(),
                'expected_revision_sha256' => $base->sha256,
                'expected_revision_size' => (int) $base->size_bytes,
                'reserved_at' => $now,
                'expires_at' => $now->copy()->addMinutes($ttl),
                'window_expires_at' => $row->window_expires_at ?? $now->copy()->addMinutes($this->stage->windowMinutes()),
                'failure_code' => null,
                'failure_message' => null,
            ])->save();
        }
    }

    /**
     * Os bytes exatos da revisão reservada (conferidos contra o resumo antes de sair).
     *
     * @throws GovBrReturnException
     */
    public function download(SignerContext $context, Request $request, string $requestUlid): StreamedResponse
    {
        $this->assertAuthenticated($context, $request);
        $row = $this->ownedRow($context, $requestUlid);

        if (! $row->reservationActive()) {
            if ($row->status === ExternalSignatureRequestStatus::Pending) {
                $this->stage->releaseReservation($row, 'reservation_expired', 'A versão reservada venceu antes da devolução. Reserve e baixe de novo para assinar.');
            }

            throw new GovBrReturnException('not_reserved', 'Não há versão reservada para você baixar agora. Reserve a versão para assinar.', 409, 'reserve');
        }

        $version = $this->expectedVersion($row);

        if ($version === null || ! hash_equals((string) $row->expected_revision_sha256, (string) $this->storage->sha256($version))) {
            $this->stage->releaseReservation($row, 'base_changed', 'A versão do documento mudou. Reserve e baixe de novo para assinar.');

            throw new GovBrReturnException('base_changed', 'A versão do documento mudou. Reserve e baixe de novo para assinar.', 409, 'reserve');
        }

        /** @var Document|null $document */
        $document = Document::withoutOrganizationScope()->find($row->document_id);
        $filename = $this->storage->downloadFilename(
            Str::slug((string) ($document->name ?? 'documento')).'-para-assinar-no-gov-br',
            'documento-para-assinar-no-gov-br',
            'pdf',
        );

        return $this->storage->stream($version, $filename, 'attachment', 'application/pdf');
    }

    /**
     * Recebe o arquivo assinado no portal, confere e aceita ou recusa.
     *
     * @throws GovBrReturnException
     */
    public function submit(SignerContext $context, Request $request, string $requestUlid, UploadedFile $file): ExternalSignatureRequest
    {
        $correlationId = (string) Str::ulid();

        try {
            $this->assertAuthenticated($context, $request);
            $row = $this->ownedRow($context, $requestUlid);

            return $this->receive($context, $request, $row, $file, $correlationId);
        } finally {
            $this->discardUpload($file);
        }
    }

    /**
     * @throws GovBrReturnException
     */
    private function receive(SignerContext $context, Request $request, ExternalSignatureRequest $row, UploadedFile $file, string $correlationId): ExternalSignatureRequest
    {
        if ($row->status === ExternalSignatureRequestStatus::Completed) {
            throw new GovBrReturnException('already_completed', 'O arquivo assinado deste documento já foi recebido e conferido.', 409);
        }

        if (! $this->receivesSignatures($context)) {
            throw new GovBrReturnException('envelope_closed', 'Este documento não está mais recebendo assinaturas pelo portal.', 409);
        }

        if ($row->status !== ExternalSignatureRequestStatus::Pending) {
            throw new GovBrReturnException('not_reserved', 'Não há versão reservada para este documento. Reserve e baixe a versão para assinar.', 409);
        }

        if (! $row->reservationActive()) {
            $this->stage->releaseReservation($row, 'reservation_expired', 'A versão reservada venceu antes da devolução. Reserve e baixe de novo para assinar.');

            throw new GovBrReturnException('reservation_expired', 'A versão reservada venceu antes da devolução. Reserve, baixe e assine de novo.', 409);
        }

        /** @var Document $document */
        $document = Document::withoutOrganizationScope()->findOrFail($row->document_id);
        $workDir = TemporaryDirectory::create($this->tool->temporaryRoot(), 'govbr-upload-');

        try {
            $returned = $this->copyUpload($file, $workDir->path('devolvido.pdf'));
            $received = ['sha256' => (string) hash_file('sha256', $returned), 'size' => (int) filesize($returned)];

            $expected = $this->expectedVersion($row);
            $latest = $this->store->latest($document);

            if ($expected === null || $latest === null || (int) $latest->getKey() !== (int) $expected->getKey()) {
                return $this->baseChanged($row, $received, $request, $correlationId);
            }

            $expectedPath = $this->storage->copyToTemporary($expected, $workDir, 'esperada.pdf');

            if (! hash_equals((string) $row->expected_revision_sha256, (string) hash_file('sha256', $expectedPath))) {
                return $this->baseChanged($row, $received, $request, $correlationId);
            }

            if (! $this->startsWith($returned, $expectedPath)) {
                // Barato e sem Python: não é a revisão entregue (ou o portal regravou o arquivo).
                $decision = GovBrReturnDecision::decide(['accepted' => false, 'problems' => ['base_not_prefix'], 'prefix_preserved' => false], false, false, false, false);

                $this->reject($row, $decision, $received, $request, $correlationId);
            }

            $trustRoots = $this->anchors->verifiedPaths();
            $informedCpf = $this->informedCpf($context);

            try {
                $result = $this->tool->verify(
                    $expectedPath,
                    $returned,
                    $trustRoots,
                    $this->permittedLevels(),
                    $informedCpf,
                    $correlationId,
                );
            } catch (PdfToolInputRejectedException $exception) {
                $code = GovBrRejections::knows($exception->errorCode) ? $exception->errorCode : 'invalid_pdf';
                $decision = GovBrReturnDecision::decide(['accepted' => false, 'problems' => [$code]], false, false, false, false);

                $this->reject($row, $decision, $received, $request, $correlationId);
            } catch (PdfToolException $exception) {
                $this->logger->error('gov.br (devolução): conferência indisponível.', [
                    'request_ulid' => $row->ulid,
                    'error_code' => $exception->errorCode,
                    'correlation_id' => $correlationId,
                ]);

                throw new GovBrReturnException('verification_unavailable', 'A conferência do arquivo está indisponível no momento. Tente de novo em alguns minutos.', 503);
            }

            $decision = GovBrReturnDecision::decide(
                $result,
                $trustRoots !== [],
                $informedCpf !== null,
                filter_var($this->config->get('assinavelox.govbr.require_holder_cpf', true), FILTER_VALIDATE_BOOLEAN),
                filter_var($this->config->get('assinavelox.govbr.accept_test_certificates', false), FILTER_VALIDATE_BOOLEAN),
            );

            if (! $decision->accepted) {
                $this->reject($row, $decision, $received, $request, $correlationId);
            }

            try {
                $this->lock->run(
                    (int) $row->envelope_id,
                    fn (): DocumentVersion => $this->store->store(
                        $context->envelope,
                        $document,
                        $expected,
                        $returned,
                        fn (DocumentVersion $version) => $this->complete($row, $version, $decision, $received, $request),
                        $correlationId,
                    ),
                    max(0, (int) $this->config->get('assinavelox.govbr.lock_wait_seconds', 20)),
                );
            } catch (StaleRevisionException) {
                return $this->baseChanged($row, $received, $request, $correlationId);
            } catch (LockTimeoutException) {
                throw new GovBrReturnException('busy', 'Outra assinatura está sendo gravada neste documento agora. Envie o arquivo de novo em instantes.', 409);
            }
        } finally {
            $workDir->delete();
        }

        $this->logger->info('gov.br (devolução): arquivo aceito.', [
            'request_ulid' => $row->ulid,
            'signature_kind' => $decision->kind?->value,
            'correlation_id' => $correlationId,
        ]);

        $this->resumeFinalization($context);

        return $row->refresh();
    }

    /**
     * @param  array{sha256: string, size: int}  $received
     */
    private function complete(ExternalSignatureRequest $row, DocumentVersion $version, GovBrReturnDecision $decision, array $received, Request $request): void
    {
        $signature = is_array($decision->result['signature'] ?? null) ? $decision->result['signature'] : [];
        $holder = is_array($signature['holder'] ?? null) ? $signature['holder'] : [];
        $string = static fn (mixed $value, int $max): ?string => is_string($value) && $value !== '' ? mb_substr(self::maskCpf($value), 0, $max) : null;
        $date = static fn (mixed $value): ?Carbon => is_string($value) && $value !== '' ? Carbon::parse($value) : null;

        $row->forceFill([
            'status' => ExternalSignatureRequestStatus::Completed,
            'signed_document_version_id' => $version->getKey(),
            'signature_kind' => $decision->kind,
            'trusted' => ($signature['trusted'] ?? false) === true,
            'field_name' => $string($signature['field_name'] ?? null, 128),
            'signer_subject' => $string($signature['signer_subject'] ?? null, 512),
            'signer_issuer' => $string($signature['issuer'] ?? null, 512),
            'signer_serial' => $string($signature['serial_hex'] ?? null, 128),
            'signer_fingerprint_sha256' => $string($signature['cert_fingerprint_sha256'] ?? null, 64),
            'signer_not_before' => $date($signature['not_before'] ?? null),
            'signer_not_after' => $date($signature['not_after'] ?? null),
            'holder_name' => $string($holder['name'] ?? null, 255),
            'holder_cpf_masked' => $string($holder['cpf_masked'] ?? null, 20),
            'holder_cpf_match' => $string($holder['cpf_match'] ?? null, 16),
            'is_test_certificate' => ($signature['test_certificate'] ?? false) === true,
            'validation_result' => $decision->checks() + [
                'signed_sha256' => $version->sha256,
                'expected_revision_sha256' => $row->expected_revision_sha256,
                'signing_time' => $signature['signing_time'] ?? null,
                // O que esta plataforma NÃO afirma sobre a assinatura devolvida.
                'profile' => null,
                'timestamp' => 'not_evaluated',
                'long_term_validation' => false,
            ],
            'completed_at' => Carbon::now(),
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        $this->recordReturn($row, ExternalSignatureReturn::OUTCOME_ACCEPTED, null, $decision->checks(), $received, $request);
    }

    /**
     * @param  array{sha256: string, size: int}  $received
     *
     * @throws GovBrReturnException
     */
    private function reject(ExternalSignatureRequest $row, GovBrReturnDecision $decision, array $received, Request $request, string $correlationId): never
    {
        $code = (string) $decision->rejectionCode;
        $message = GovBrRejections::message($code);

        $row->forceFill([
            'attempts' => $row->attempts + 1,
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();

        $this->recordReturn($row, ExternalSignatureReturn::OUTCOME_REJECTED, $code, $decision->checks(), $received, $request);

        $this->logger->info('gov.br (devolução): arquivo recusado.', [
            'request_ulid' => $row->ulid,
            'rejection_code' => $code,
            'correlation_id' => $correlationId,
        ]);

        throw new GovBrReturnException($code, $message, 422);
    }

    /**
     * @param  array{sha256: string, size: int}  $received
     *
     * @throws GovBrReturnException
     */
    private function baseChanged(ExternalSignatureRequest $row, array $received, Request $request, string $correlationId): never
    {
        $message = 'Outra assinatura entrou no documento depois que você baixou a versão para assinar. Reserve e baixe a nova versão, assine no portal e envie de novo.';

        $this->recordReturn($row, ExternalSignatureReturn::OUTCOME_REJECTED, 'base_changed', ['decision' => 'base_changed'], $received, $request);
        $row->forceFill(['attempts' => $row->attempts + 1])->save();
        $this->stage->releaseReservation($row, 'base_changed', $message);

        $this->logger->info('gov.br (devolução): a revisão reservada deixou de ser a mais recente.', [
            'request_ulid' => $row->ulid,
            'correlation_id' => $correlationId,
        ]);

        throw new GovBrReturnException('base_changed', $message, 409);
    }

    /**
     * @param  array<string, mixed>  $checks
     * @param  array{sha256: string, size: int}  $received
     */
    private function recordReturn(ExternalSignatureRequest $row, string $outcome, ?string $code, array $checks, array $received, Request $request): void
    {
        $record = new ExternalSignatureReturn;
        $record->forceFill([
            'organization_id' => $row->organization_id,
            'envelope_id' => $row->envelope_id,
            'external_signature_request_id' => $row->getKey(),
            'recipient_id' => $row->recipient_id,
            'received_sha256' => $received['sha256'],
            'received_size' => $received['size'],
            'expected_revision_sha256' => $row->expected_revision_sha256,
            'outcome' => $outcome,
            'rejection_code' => $code,
            'checks' => $checks,
            'ip_address' => $request->ip(),
        ]);
        $record->save();
    }

    /**
     * @throws GovBrReturnException
     */
    private function copyUpload(UploadedFile $file, string $target): string
    {
        $source = $file->getRealPath();

        if (! $file->isValid() || $source === false || ! is_file($source)) {
            throw new GovBrReturnException('upload_failed', GovBrRejections::message('upload_failed'));
        }

        if ((int) filesize($source) > $this->maxUploadMb() * 1024 * 1024) {
            throw new GovBrReturnException('file_too_large', GovBrRejections::message('file_too_large'));
        }

        $head = (string) file_get_contents($source, false, null, 0, 1024);

        if (! str_contains($head, '%PDF-')) {
            throw new GovBrReturnException('not_pdf', GovBrRejections::message('not_pdf'));
        }

        if (! @copy($source, $target)) {
            throw new GovBrReturnException('upload_failed', GovBrRejections::message('upload_failed'), 500);
        }

        return $target;
    }

    private function startsWith(string $returned, string $expected): bool
    {
        $expectedSize = (int) filesize($expected);

        if ((int) filesize($returned) <= $expectedSize) {
            return false;
        }

        $a = @fopen($returned, 'rb');
        $b = @fopen($expected, 'rb');

        if ($a === false || $b === false) {
            return false;
        }

        try {
            $remaining = $expectedSize;

            while ($remaining > 0) {
                $length = min(1024 * 1024, $remaining);
                $left = fread($a, $length);
                $right = fread($b, $length);

                if ($left === false || $right === false || $left !== $right || strlen($right) !== $length) {
                    return false;
                }

                $remaining -= $length;
            }

            return true;
        } finally {
            fclose($a);
            fclose($b);
        }
    }

    private function discardUpload(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if ($path !== false && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * O convite ainda aceita a escolha (antes ou depois do aceite, com o envelope aberto)?
     */
    private function acceptsIntent(SignerContext $context): bool
    {
        return in_array($context->state, [
            SignerContext::STATE_ACTIVE,
            SignerContext::STATE_SIGNED_PENDING_OTHERS,
            SignerContext::STATE_FINALIZING,
        ], true) && ! $this->hasFinal($context);
    }

    /**
     * Revisões podem ser reservadas e recebidas: finalização em curso, base pronta, sem `final`.
     */
    private function receivesSignatures(SignerContext $context): bool
    {
        $envelope = $context->envelope->refresh();

        return $envelope->status === EnvelopeStatus::Finalizing && ! $this->hasFinal($context);
    }

    private function ready(SignerContext $context): bool
    {
        if (! $context->hasSigned() || ! $this->receivesSignatures($context)) {
            return false;
        }

        foreach ($context->sentDocuments() as $item) {
            if ($this->store->latest($item['document']) === null) {
                return false;
            }
        }

        return true;
    }

    private function hasFinal(SignerContext $context): bool
    {
        $ids = array_map(static fn (array $item): int => (int) $item['document']->getKey(), $context->sentDocuments());

        return $ids !== [] && DocumentVersion::withoutOrganizationScope()
            ->whereIn('document_id', $ids)
            ->where('kind', DocumentVersionKind::Final->value)
            ->exists();
    }

    /**
     * @throws GovBrReturnException
     */
    private function assertAuthenticated(SignerContext $context, Request $request): void
    {
        if (! $this->authenticated($context, $request)) {
            throw new GovBrReturnException('not_authenticated', 'Sua sessão expirou. Confirme o código enviado para você para continuar.', 403, 'session');
        }
    }

    /**
     * @return Collection<int, ExternalSignatureRequest>
     */
    private function rows(SignerContext $context): Collection
    {
        return ExternalSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $context->envelope->getKey())
            ->where('recipient_id', $context->recipient->getKey())
            ->get()
            ->keyBy('document_id');
    }

    private function rowFor(SignerContext $context, Document $document): ?ExternalSignatureRequest
    {
        /** @var ExternalSignatureRequest|null $row */
        $row = ExternalSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $context->envelope->getKey())
            ->where('recipient_id', $context->recipient->getKey())
            ->where('document_id', $document->getKey())
            ->first();

        return $row;
    }

    /**
     * @throws GovBrReturnException
     */
    private function ownedRow(SignerContext $context, string $requestUlid): ExternalSignatureRequest
    {
        /** @var ExternalSignatureRequest|null $row */
        $row = ExternalSignatureRequest::withoutOrganizationScope()
            ->where('ulid', $requestUlid)
            ->where('envelope_id', $context->envelope->getKey())
            ->where('recipient_id', $context->recipient->getKey())
            ->first();

        if ($row === null) {
            throw new GovBrReturnException('not_found', 'Pedido não encontrado.', 404, 'request');
        }

        return $row;
    }

    private function expectedVersion(ExternalSignatureRequest $row): ?DocumentVersion
    {
        if ($row->expected_document_version_id === null) {
            return null;
        }

        /** @var DocumentVersion|null $version */
        $version = DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $row->document_id)
            ->whereKey($row->expected_document_version_id)
            ->first();

        return $version;
    }

    private function informedCpf(SignerContext $context): ?string
    {
        $value = SigningFieldValue::withoutOrganizationScope()
            ->join('signing_fields', 'signing_fields.id', '=', 'signing_field_values.signing_field_id')
            ->where('signing_field_values.recipient_id', $context->recipient->getKey())
            ->where('signing_field_values.envelope_id', $context->envelope->getKey())
            ->where('signing_fields.type', FieldType::Cpf->value)
            ->value('signing_field_values.value_text');

        $digits = preg_replace('/\D/', '', (string) $value);

        return is_string($digits) && strlen($digits) === 11 ? $digits : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestProps(SignerContext $context, ExternalSignatureRequest $row, bool $authenticated): array
    {
        $active = $row->reservationActive();
        $last = ExternalSignatureReturn::withoutOrganizationScope()
            ->where('external_signature_request_id', $row->getKey())
            ->orderByDesc('id')
            ->first();

        return [
            'id' => $row->ulid,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'expected_revision' => $active ? [
                'sha256' => $row->expected_revision_sha256,
                'size_bytes' => $row->expected_revision_size,
                'download_url' => $authenticated ? route('sign.govbr.download', ['token' => $context->token, 'pedido' => $row->ulid]) : null,
            ] : null,
            'upload_url' => $active && $authenticated ? route('sign.govbr.upload', ['token' => $context->token, 'pedido' => $row->ulid]) : null,
            'reserved_at' => $row->reserved_at?->toIso8601String(),
            'expires_at' => $active ? $row->expires_at?->toIso8601String() : null,
            'window_expires_at' => $row->window_expires_at?->toIso8601String(),
            'completed_at' => $row->completed_at?->toIso8601String(),
            'attempts' => $row->attempts,
            'failure' => $row->failure_code === null ? null : ['code' => $row->failure_code, 'message' => $row->failure_message],
            'last_return' => $last === null ? null : [
                'outcome' => $last->outcome,
                'rejection_code' => $last->rejection_code,
                'received_sha256' => $last->received_sha256,
                'received_at' => $last->created_at?->toIso8601String(),
            ],
            'signature' => $row->status !== ExternalSignatureRequestStatus::Completed || $row->signature_kind === null ? null : [
                'kind' => $row->signature_kind->value,
                'label' => $row->signature_kind->label().($row->is_test_certificate ? ' — certificado de TESTE' : ''),
                'description' => $row->signature_kind->description(),
                'holder_name' => $row->holder_name,
                'holder_cpf_masked' => $row->holder_cpf_masked,
                'issuer' => $row->signer_issuer,
                'fingerprint_sha256' => $row->signer_fingerprint_sha256,
                'valid_from' => $row->signer_not_before?->toIso8601String(),
                'valid_to' => $row->signer_not_after?->toIso8601String(),
                'trusted' => $row->trusted,
                'is_test' => $row->is_test_certificate,
                'signed_at' => $row->completed_at?->toIso8601String(),
            ],
        ];
    }

    private function resumeFinalization(SignerContext $context): void
    {
        $envelope = $context->envelope->refresh();

        if ($envelope->status !== EnvelopeStatus::Finalizing) {
            return;
        }

        try {
            FinalizeEnvelope::dispatch((int) $envelope->getKey(), (int) $envelope->organization_id, $envelope->finalization_key);
        } catch (Throwable $exception) {
            $this->logger->error('gov.br (devolução): a retomada da finalização falhou; o envelope continua em finalizing.', [
                'envelope_ulid' => $envelope->ulid,
                'exception' => $exception::class,
                'alert' => 'envelope_finalization_dispatch_failed',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function permittedLevels(): array
    {
        $levels = array_values(array_intersect(
            array_map('strtoupper', array_map('strval', (array) $this->config->get('assinavelox.govbr.permitted_modification_levels', ['NONE', 'FORM_FILLING']))),
            ['NONE', 'FORM_FILLING', 'ANNOTATIONS'],
        ));

        return $levels === [] ? ['NONE', 'FORM_FILLING'] : $levels;
    }

    private function reservationMinutes(): int
    {
        return max(5, (int) $this->config->get('assinavelox.govbr.reservation_ttl_minutes', 120));
    }

    private function maxUploadMb(): int
    {
        return max(1, min(100, (int) $this->config->get('assinavelox.govbr.max_upload_mb', 100)));
    }

    private function portalHost(): string
    {
        return (string) (parse_url((string) $this->config->get('assinavelox.govbr.portal_url', 'https://assinador.iti.br'), PHP_URL_HOST) ?: 'assinador.iti.br');
    }

    private function validatorHost(): string
    {
        return (string) (parse_url((string) $this->config->get('assinavelox.govbr.validator_url', 'https://validar.iti.gov.br'), PHP_URL_HOST) ?: 'validar.iti.gov.br');
    }

    private static function maskCpf(string $text): string
    {
        return (string) preg_replace_callback('/(?<!\d)\d{3}\.?(\d{3})\.?(\d{3})-?\d{2}(?!\d)/', static fn (array $m): string => '***.'.$m[1].'.'.$m[2].'-**', $text);
    }
}
