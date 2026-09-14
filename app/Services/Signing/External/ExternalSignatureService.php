<?php

namespace App\Services\Signing\External;

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\ExternalSignatureKind;
use App\Enums\FieldType;
use App\Enums\LocalSignerComponent;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Enums\PendingExternalSignatureStatus;
use App\Integrations\LocalSigner\Contracts\LocalSignerBridge;
use App\Integrations\LocalSigner\Exceptions\LocalSignerUnavailable;
use App\Integrations\LocalSigner\LocalSignerBridges;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\PendingExternalSignature;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningFieldValue;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Pdf\Exceptions\PdfToolException;
use App\Services\Pdf\Exceptions\PdfToolProcessingException;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Signing\Certificates\CertificateInspection;
use App\Services\Signing\Certificates\EnvelopeSigningLock;
use App\Services\Signing\Certificates\Exceptions\StaleRevisionException;
use App\Services\Signing\Certificates\IncrementalRevisions;
use App\Services\Signing\External\Exceptions\ExternalSignatureException;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerDownloadGrants;
use App\Services\Signing\SignerSessions;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assinatura do participante feita FORA do servidor — componente local A3 (Fase 3 §3.4).
 *
 * ```
 * aceite ─► (todos aceitaram) envelope em finalizing, base congelada (pre_signature)
 *   │
 *   ├─ intent            escolha registrada (participant_signature_requests, método local_component)
 *   ├─ prepare(doc)      sob o lock do envelope: reserva a revisão MAIS RECENTE do documento,
 *   │                    `pdftool prepare-external` → revisão pendente + digest; TTL curto
 *   ├─ (navegador ↔ componente local: o token assina o digest; o servidor não vê a chave)
 *   └─ submit(sig|cms)   consumo ÚNICO da reserva; sob o lock: a revisão-base ainda é a mais
 *                        recente? `pdftool embed-external` (valida o arquivo inteiro) →
 *                        gravação compare-and-set → document_version + participant_signature
 * ```
 *
 * Garantias (todas testadas em tests/Feature/Phase3/External):
 *
 * - **sem revisões irmãs**: lock do envelope (o MESMO do §2.12), no máximo uma reserva ativa
 *   por documento (índice único `reservation_key`), e a gravação só entra se a base usada
 *   ainda for a mais recente ({@see IncrementalRevisions::storeSigned()});
 * - **digest expirado, reutilizado ou de outra revisão é recusado**: TTL, update condicional
 *   `pending → embedding` (uma vez só) e o pdftool conferindo digest, certificado e assinatura;
 * - **nenhum segredo**: o servidor não recebe chave, senha nem sessão do token; o digest só
 *   sai na resposta da preparação — nunca em log, evento ou fila;
 * - **rótulos honestos**: `participant_a3` só com componente REAL habilitado e certificado que
 *   declara A3; tudo o que vem do simulador é `participant_external` + "simulado".
 */
final class ExternalSignatureService
{
    public const METHOD = 'local_component';

    public const FIELD_PREFIX = 'AV_Externo_';

    public const MODES = [PendingExternalSignature::MODE_RAW, PendingExternalSignature::MODE_CMS];

    public const MAX_SIGNATURE_BYTES = 2048;

    public function __construct(
        private readonly ExternalSignatureTool $tool,
        private readonly PendingSignatureFiles $files,
        private readonly ExternalTrustAnchors $anchors,
        private readonly LocalSignerBridges $bridges,
        private readonly IncrementalRevisions $revisions,
        private readonly EnvelopeSigningLock $lock,
        private readonly DocumentStorage $storage,
        private readonly PdfToolClient $client,
        private readonly SignerSessions $sessions,
        private readonly SignerDownloadGrants $grants,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public static function fieldName(Recipient $recipient): string
    {
        return self::FIELD_PREFIX.$recipient->ulid;
    }

    // ------------------------------------------------------------------ acesso

    public function offeredTo(SignerContext $context): bool
    {
        return ExternalSigningFeature::enabledFor($context->organization)
            && ExternalSigningFeature::allowsRecipient($context->recipient)
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

    public function simulatorAvailable(): bool
    {
        return $this->bridges->simulator()->detect()->available;
    }

    // ------------------------------------------------------------------ estado

    /**
     * @return array<string, mixed>
     */
    public function state(SignerContext $context, Request $request): array
    {
        $row = $this->findRequest($context);
        $external = $row !== null && $row->getAttribute('signature_method') === self::METHOD;
        $otherMethod = $row !== null && ! $external && $row->status->isActive();
        $authenticated = $this->authenticated($context, $request);
        $readiness = $this->readiness($context);
        $open = $this->isOpen($context);
        $status = $external ? $row->status : null;

        $stage = match (true) {
            $otherMethod => 'other_method',
            $status === null => $readiness['ready'] ? 'ready_to_sign' : ($open ? 'choose' : 'closed'),
            $status === ParticipantSignatureRequestStatus::Requested => $readiness['ready'] ? 'ready_to_sign' : 'awaiting_others',
            default => $status->value,
        };

        $canPrepare = $authenticated && $open && $readiness['ready'] && ! $otherMethod
            && ($status === null || $status === ParticipantSignatureRequestStatus::Requested);
        $trust = $this->anchors->resolve();

        return [
            'available' => true,
            'method' => self::METHOD,
            'authenticated' => $authenticated,
            'stage' => $stage,
            'ready' => $readiness['ready'],
            'message' => $otherMethod
                ? 'Você já optou por assinar com o certificado A1 (arquivo) neste documento.'
                : $readiness['message'],
            'can_request' => $authenticated && $open && ! $otherMethod
                && ($row === null || in_array($row->status, [ParticipantSignatureRequestStatus::Withdrawn], true)),
            'can_withdraw' => $authenticated && $status === ParticipantSignatureRequestStatus::Requested,
            'can_prepare' => $canPrepare,
            'request' => $external ? $this->requestProps($row) : null,
            'documents' => $readiness['ready'] && ($external || $row === null) ? $this->documentsState($context, $external ? $row : null) : [],
            'components' => $this->bridges->statuses(),
            'local_component' => $this->bridges->nexuProtocol(),
            'chain' => [
                'anchors_pinned' => $trust['pinned'],
                'label' => ExternalSignatureLabels::chain(false, $trust['pinned']),
                'revocation' => 'not_checked',
                'revocation_label' => ExternalSignatureLabels::REVOCATION_NOT_CHECKED,
            ],
            'limits' => [
                'pending_ttl_minutes' => ExternalSigningFeature::ttlMinutes(),
                'hash_function' => 'SHA256',
                'modes' => self::MODES,
                'max_signature_bytes' => self::MAX_SIGNATURE_BYTES,
                'max_certificate_kb' => $this->maxCertificateKb(),
                'max_chain_certificates' => $this->maxChain(),
                'max_cms_kb' => $this->maxCmsKb(),
            ],
            'notices' => ExternalSignatureLabels::notices(),
            'endpoints' => [
                'show' => route('sign.external.show', ['token' => $context->token]),
                'intent' => route('sign.external.intent', ['token' => $context->token]),
                'withdraw' => route('sign.external.withdraw', ['token' => $context->token]),
                'prepare' => route('sign.external.prepare', ['token' => $context->token]),
                'submit' => route('sign.external.submit', ['token' => $context->token]),
                'simulator_certificate' => $this->simulatorAvailable() ? route('sign.external.simulator.certificate', ['token' => $context->token]) : null,
                'simulator_sign' => $this->simulatorAvailable() ? route('sign.external.simulator.sign', ['token' => $context->token]) : null,
            ],
        ];
    }

    // ------------------------------------------------------------------ escolha

    /**
     * @throws ExternalSignatureException
     */
    public function requestIntent(SignerContext $context, Request $request, ?string $component = null): ParticipantSignatureRequest
    {
        $this->assertAuthenticated($context, $request);
        $this->assertOpen($context);

        $row = $this->findRequest($context);
        $external = $row !== null && $row->getAttribute('signature_method') === self::METHOD;

        if ($row !== null && ! $external && $row->status->isActive()) {
            throw new ExternalSignatureException('other_method_chosen', 'Você já optou por assinar com o certificado A1 (arquivo) neste documento. Desista dessa opção antes de escolher o componente local.', 409, 'method');
        }

        if ($row !== null && $row->status === ParticipantSignatureRequestStatus::Expired) {
            throw new ExternalSignatureException('window_closed', 'O prazo para assinar com o próprio certificado neste documento já terminou.', 409, 'method');
        }

        if ($external && $row->status->isActive()) {
            return $row;
        }

        $converting = $row !== null;
        $row ??= new ParticipantSignatureRequest;
        $row->forceFill([
            'organization_id' => $context->envelope->organization_id,
            'envelope_id' => $context->envelope->getKey(),
            'recipient_id' => $context->recipient->getKey(),
            'status' => ParticipantSignatureRequestStatus::Requested,
            'signature_method' => self::METHOD,
            'signing_component' => LocalSignerComponent::tryFrom((string) $component)?->value,
            'failure_code' => null,
            'failure_message' => null,
            'closed_at' => null,
        ]);

        if ($converting) {
            // Um pedido A1 desistido vira pedido do componente local: os fatos do outro
            // certificado não descrevem mais nada.
            $row->forceFill([
                'consent_version' => null, 'consent_statement' => null, 'consented_at' => null, 'consent_ip' => null,
                'subject' => null, 'subject_cn' => null, 'issuer' => null, 'issuer_cn' => null, 'serial_number' => null,
                'fingerprint_sha256' => null, 'not_before' => null, 'not_after' => null, 'holder_cpf_masked' => null,
                'is_test_certificate' => false, 'certificate_facts' => null, 'window_expires_at' => null,
            ]);
        }

        $row->save();

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ParticipantCertificateRequested, [
            'request_ulid' => $row->ulid,
            'method' => self::METHOD,
        ]);

        return $row;
    }

    /**
     * @throws ExternalSignatureException
     */
    public function withdraw(SignerContext $context, Request $request): ParticipantSignatureRequest
    {
        $this->assertAuthenticated($context, $request);

        $row = $this->findRequest($context);

        if ($row === null || $row->getAttribute('signature_method') !== self::METHOD) {
            throw new ExternalSignatureException('not_requested', 'Você não optou por assinar com componente local neste documento.', 409, 'method');
        }

        if ($row->status !== ParticipantSignatureRequestStatus::Requested) {
            throw new ExternalSignatureException(
                'cannot_withdraw',
                $row->status === ParticipantSignatureRequestStatus::Applied ? 'A sua assinatura já foi incorporada ao documento.' : 'Não é mais possível desistir neste momento.',
                409,
                'method',
            );
        }

        $this->discardActive($row, 'withdrawn');

        $row->forceFill([
            'status' => ParticipantSignatureRequestStatus::Withdrawn,
            'closed_at' => Carbon::now(),
        ])->save();

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ParticipantCertificateWithdrawn, [
            'request_ulid' => $row->ulid,
            'method' => self::METHOD,
        ]);

        $this->resumeFinalization($context->envelope);

        return $row;
    }

    // ------------------------------------------------------------------ preparação

    /**
     * Reserva a revisão mais recente do documento e devolve o digest a assinar.
     *
     * @param  list<string>  $chain  Base64 (DER) ou PEM
     * @return array<string, mixed>
     *
     * @throws ExternalSignatureException
     */
    public function prepare(
        SignerContext $context,
        Request $request,
        string $documentUlid,
        string $componentName,
        string $mode,
        string $certificate,
        array $chain,
    ): array {
        $this->assertAuthenticated($context, $request);
        $this->assertOpen($context);

        $readiness = $this->readiness($context);

        if (! $readiness['ready']) {
            $this->requestIntent($context, $request, $componentName);

            throw new ExternalSignatureException('not_ready', (string) $readiness['message'], 409, 'document_id');
        }

        $bridge = $this->bridges->tryFrom($componentName)
            ?? throw new ExternalSignatureException('component_unknown', 'Componente de assinatura desconhecido.', 422, 'component');
        $status = $bridge->detect();

        if (! $status->available) {
            throw new ExternalSignatureException(
                'component_unavailable',
                $status->reason === 'production_disabled'
                    ? 'Este componente de assinatura ainda não está habilitado nesta plataforma.'
                    : 'Este componente de assinatura não está disponível neste ambiente.',
                409,
                'component',
                ['reason' => $status->reason],
            );
        }

        if (! in_array($mode, self::MODES, true) || ($bridge->isSimulated() && $mode !== PendingExternalSignature::MODE_RAW)) {
            throw new ExternalSignatureException('mode_not_supported', 'Este modo de assinatura não é aceito para o componente escolhido.', 422, 'mode');
        }

        $row = $this->requestIntent($context, $request, $componentName);

        if ($row->status !== ParticipantSignatureRequestStatus::Requested) {
            throw new ExternalSignatureException('request_closed', 'Este pedido não está mais aberto para assinatura.', 409, 'method');
        }

        $document = $this->sentDocument($context->envelope, $documentUlid);

        if ($this->alreadySigned($row, $document)) {
            throw new ExternalSignatureException('already_signed', 'Este documento já recebeu a sua assinatura.', 409, 'document_id');
        }

        $certificateDer = $this->decodeCertificate($certificate, 'certificate');
        $chainDers = [];

        if (count($chain) > $this->maxChain()) {
            throw new ExternalSignatureException('chain_too_long', 'A cadeia enviada tem certificados demais.', 422, 'chain');
        }

        foreach ($chain as $item) {
            $chainDers[] = $this->decodeCertificate($item, 'chain');
        }

        if ($bridge->isSimulated()) {
            $expected = base64_decode($this->simulatorCertificate()['certificate'], true);

            if ($expected === false || ! hash_equals(hash('sha256', $expected), hash('sha256', $certificateDer))) {
                throw new ExternalSignatureException('certificate_mismatch', 'O simulador só assina com o certificado que ele mesmo fornece.', 422, 'certificate');
            }
        }

        $correlationId = (string) Str::ulid();
        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'ext-prep-');

        try {
            $certificatePath = $workDir->path('certificado.der');
            file_put_contents($certificatePath, $certificateDer);
            $chainPaths = [];

            foreach ($chainDers as $index => $der) {
                $chainPaths[] = $path = $workDir->path(sprintf('cadeia-%d.der', $index));
                file_put_contents($path, $der);
            }

            try {
                $pending = $this->lock->run(
                    (int) $context->envelope->getKey(),
                    fn (): PendingExternalSignature => $this->prepareLocked($context, $row, $document, $bridge, $mode, $certificatePath, $chainPaths, $workDir, $correlationId),
                    ExternalSigningFeature::lockWaitSeconds(),
                );
            } catch (LockTimeoutException) {
                throw $this->busy();
            }
        } finally {
            $workDir->delete();
        }

        return ['pending' => $this->pendingProps($context, $pending, true)];
    }

    // ------------------------------------------------------------------ envio

    /**
     * Assinatura bruta + certificado (+ cadeia), ou CMS pronto.
     *
     * @param  list<string>  $chain
     *
     * @throws ExternalSignatureException
     */
    public function submit(
        SignerContext $context,
        Request $request,
        string $pendingUlid,
        string $mode,
        ?string $signature,
        ?string $certificate,
        array $chain,
        ?string $cms,
    ): void {
        $this->assertAuthenticated($context, $request);

        $pending = $this->findPending($context, $pendingUlid);

        if ($pending->component === LocalSignerComponent::Simulated || $pending->is_simulated) {
            // O simulador assina no servidor, com o digest do próprio servidor: a custódia fica clara.
            throw new ExternalSignatureException('simulator_only', 'Uma preparação do simulador só é concluída pelo próprio simulador.', 409, 'pending_id');
        }

        if ($mode === PendingExternalSignature::MODE_RAW) {
            $signatureBytes = $this->decodeBinary((string) $signature, self::MAX_SIGNATURE_BYTES, 'signature');
            $certificateDer = $this->decodeCertificate((string) $certificate, 'certificate');
            $chainDers = array_map(fn (string $item): string => $this->decodeCertificate($item, 'chain'), array_slice($chain, 0, $this->maxChain()));

            $this->embed($context, $pending, $mode, $signatureBytes, $certificateDer, $chainDers, null);

            return;
        }

        $cmsDer = $this->decodeBinary((string) $cms, $this->maxCmsKb() * 1024, 'cms', true);
        $this->embed($context, $pending, $mode, null, null, [], $cmsDer);
    }

    // ------------------------------------------------------------------ simulador

    /**
     * Certificado do SIMULADOR (só em teste/local). Mesmo formato de `getSigningCertificate()`.
     *
     * @return array<string, mixed>
     */
    public function simulatorCertificate(): array
    {
        try {
            $certificate = $this->bridges->simulator()->signingCertificate();
        } catch (LocalSignerUnavailable $exception) {
            throw new ExternalSignatureException('component_unavailable', 'O simulador de componente local não está disponível neste ambiente.', 404, 'component', ['reason' => $exception->reason]);
        }

        return $certificate->toArray() + [
            'component' => LocalSignerComponent::Simulated->value,
            'simulated' => true,
            'label' => LocalSignerComponent::Simulated->label(),
        ];
    }

    /**
     * O simulador assina o digest da reserva (o do próprio servidor) e a assinatura segue o
     * caminho normal de incorporação. Nenhum token é usado.
     *
     * @throws ExternalSignatureException
     */
    public function simulateSignature(SignerContext $context, Request $request, string $pendingUlid): void
    {
        $this->assertAuthenticated($context, $request);

        $pending = $this->findPending($context, $pendingUlid);

        if ($pending->component !== LocalSignerComponent::Simulated || ! $pending->is_simulated) {
            throw new ExternalSignatureException('not_simulated', 'Esta preparação não é do simulador.', 409, 'pending_id');
        }

        $this->assertConsumable($pending);
        $simulator = $this->bridges->simulator();

        try {
            $certificate = $simulator->signingCertificate();
            $signature = $simulator->signDigest($certificate->keyHandle, (string) hex2bin($pending->digest_hex), 'SHA256');
        } catch (LocalSignerUnavailable $exception) {
            throw new ExternalSignatureException('component_unavailable', 'O simulador de componente local não está disponível neste ambiente.', 409, 'component', ['reason' => $exception->reason]);
        }

        $this->embed(
            $context,
            $pending,
            PendingExternalSignature::MODE_RAW,
            (string) base64_decode($signature->signature, true),
            (string) base64_decode($signature->certificate, true),
            array_map(static fn (string $item): string => (string) base64_decode($item, true), $certificate->chain),
            null,
        );
    }

    // ------------------------------------------------------------------ manutenção

    /**
     * Vence reservas cujo prazo passou e destrava incorporações interrompidas (processo que
     * morreu com a linha em `embedding`). Apaga os arquivos de cada uma.
     */
    public function expireStale(?int $documentId = null): int
    {
        $now = Carbon::now();
        $stuckBefore = $now->copy()->subSeconds(max(60, (int) $this->config->get('assinavelox.participant_a1.lock_seconds', 900)));

        $rows = PendingExternalSignature::withoutOrganizationScope()
            ->where(function ($query) use ($now, $stuckBefore): void {
                $query->where(fn ($q) => $q->where('status', PendingExternalSignatureStatus::Pending->value)->where('expires_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->where('status', PendingExternalSignatureStatus::Embedding->value)->where('updated_at', '<', $stuckBefore));
            })
            ->when($documentId !== null, fn ($query) => $query->where('document_id', $documentId))
            ->get();

        foreach ($rows as $row) {
            $interrupted = $row->status === PendingExternalSignatureStatus::Embedding;
            $this->close($row, $interrupted ? PendingExternalSignatureStatus::Rejected : PendingExternalSignatureStatus::Expired, $interrupted ? 'embed_interrupted' : 'expired', $interrupted
                ? 'A incorporação da assinatura foi interrompida. Prepare de novo.'
                : 'O prazo desta preparação terminou antes da assinatura. Prepare de novo.');

            $envelope = Envelope::withoutOrganizationScope()->find($row->envelope_id);

            if ($envelope instanceof Envelope) {
                SignerAudit::system($envelope, AuditEventType::ParticipantSignatureExpired, [
                    'method' => self::METHOD,
                    'pending_ulid' => $row->ulid,
                    'reason' => $interrupted ? 'embed_interrupted' : 'pending_expired',
                ], Recipient::withoutOrganizationScope()->find($row->recipient_id));
            }
        }

        return $rows->count();
    }

    // ================================================================== internos

    /**
     * @param  list<string>  $chainPaths
     *
     * @throws ExternalSignatureException
     */
    private function prepareLocked(
        SignerContext $context,
        ParticipantSignatureRequest $row,
        Document $document,
        LocalSignerBridge $bridge,
        string $mode,
        string $certificatePath,
        array $chainPaths,
        TemporaryDirectory $workDir,
        string $correlationId,
    ): PendingExternalSignature {
        $envelope = $context->envelope->refresh();
        $row->refresh();

        if ($envelope->status !== EnvelopeStatus::Finalizing || $this->hasFinal($envelope)) {
            throw new ExternalSignatureException('envelope_closed', 'Este documento não está mais recebendo assinaturas com certificado.', 409, 'document_id');
        }

        if ($row->status !== ParticipantSignatureRequestStatus::Requested) {
            throw new ExternalSignatureException('request_closed', 'Este pedido não está mais aberto para assinatura.', 409, 'method');
        }

        $this->expireStale((int) $document->getKey());

        $active = $this->activeReservation($document);

        if ($active !== null) {
            if ((int) $active->participant_signature_request_id !== (int) $row->getKey()) {
                throw new ExternalSignatureException(
                    'document_reserved',
                    'Outro participante está assinando este documento agora. Tente de novo em alguns minutos.',
                    409,
                    'document_id',
                    ['retry_after' => $active->expires_at->utc()->toIso8601String()],
                );
            }

            // Nova preparação do MESMO participante: a anterior deixa de valer.
            $this->close($active, PendingExternalSignatureStatus::Superseded, 'superseded', 'Substituída por uma nova preparação.');
        }

        $latest = $this->revisions->latest($document);

        if ($latest === null) {
            $this->resumeFinalization($envelope);

            throw new ExternalSignatureException('waiting_base', 'O documento ainda está sendo preparado para receber assinaturas. Tente de novo em instantes.', 409, 'document_id');
        }

        $input = $this->storage->copyToTemporary($latest, $workDir, 'revisao-atual.pdf');

        if (! hash_equals((string) $latest->sha256, (string) hash_file('sha256', $input))) {
            throw new ExternalSignatureException('revision_unavailable', 'A revisão atual do documento não pôde ser lida com segurança. Tente de novo.', 503, 'document_id');
        }

        $ulid = (string) Str::ulid();
        $this->files->directory($ulid);
        $pendingPath = $this->files->path($ulid, PendingSignatureFiles::PENDING);
        $statePath = $this->files->path($ulid, PendingSignatureFiles::STATE);
        $trust = $this->anchors->resolve();

        try {
            $result = $this->tool->prepare(
                $input,
                $pendingPath,
                $statePath,
                $certificatePath,
                $chainPaths,
                self::fieldName($context->recipient),
                $trust['paths'],
                (string) $this->config->get('assinavelox.external_signing.reason', ''),
                max(4096, min(65536, (int) $this->config->get('assinavelox.external_signing.bytes_reserved', 16384))),
                $correlationId,
            );
        } catch (PdfToolException $exception) {
            $this->files->delete($ulid);
            $this->rejected($context, $row, $exception->errorCode, $correlationId);

            throw new ExternalSignatureException(
                $exception instanceof PdfToolProcessingException ? 'prepare_failed' : $exception->errorCode,
                ExternalSignatureLabels::rejection($exception->errorCode),
                $exception instanceof PdfToolProcessingException ? 503 : 422,
                'certificate',
            );
        }

        /** @var array<string, mixed> $certificate */
        $certificate = is_array($result['certificate'] ?? null) ? $result['certificate'] : [];
        $chainTrust = is_array($result['chain_trust'] ?? null) ? $result['chain_trust'] : [];
        $isTest = ($certificate['test_certificate'] ?? false) === true;

        try {
            if (($result['base_sha256'] ?? null) !== $latest->sha256) {
                throw new ExternalSignatureException('revision_unavailable', 'A revisão preparada não é a revisão atual do documento. Tente de novo.', 503, 'document_id');
            }

            if ($isTest && ! filter_var($this->config->get('assinavelox.external_signing.accept_test_certificates', false), FILTER_VALIDATE_BOOLEAN)) {
                throw new ExternalSignatureException('test_certificate_not_accepted', ExternalSignatureLabels::rejection('test_certificate_not_accepted'), 422, 'certificate');
            }

            if (filter_var($this->config->get('assinavelox.external_signing.require_trusted_chain', false), FILTER_VALIDATE_BOOLEAN)
                && ($chainTrust['trusted'] ?? false) !== true) {
                throw new ExternalSignatureException('chain_not_trusted', ExternalSignatureLabels::rejection('chain_not_trusted'), 422, 'certificate');
            }

            $holder = is_array($certificate['holder'] ?? null) ? $certificate['holder'] : [];
            $declaredCpf = is_string($holder['cpf'] ?? null) ? $holder['cpf'] : null;
            $informedCpf = $this->informedCpf($context);

            if ($declaredCpf !== null && $informedCpf !== null && ! hash_equals($informedCpf, $declaredCpf)) {
                throw new ExternalSignatureException('holder_mismatch', ExternalSignatureLabels::rejection('holder_mismatch'), 422, 'certificate');
            }
        } catch (ExternalSignatureException $exception) {
            $this->files->delete($ulid);
            $this->rejected($context, $row, $exception->errorCode, $correlationId);

            throw $exception;
        }

        $icp = is_array($certificate['icp_brasil'] ?? null) ? $certificate['icp_brasil'] : [];
        $digest = $mode === PendingExternalSignature::MODE_RAW ? $result['digest_to_sign_hex'] ?? '' : $result['document_digest_hex'] ?? '';
        $now = Carbon::now();

        $pending = new PendingExternalSignature;
        $pending->forceFill([
            'ulid' => $ulid,
            'organization_id' => $envelope->organization_id,
            'envelope_id' => $envelope->getKey(),
            'recipient_id' => $context->recipient->getKey(),
            'document_id' => $document->getKey(),
            'participant_signature_request_id' => $row->getKey(),
            'base_document_version_id' => $latest->getKey(),
            'base_sha256' => $latest->sha256,
            'field_name' => self::fieldName($context->recipient),
            'status' => PendingExternalSignatureStatus::Pending,
            'mode' => $mode,
            'component' => $bridge->component(),
            'is_simulated' => $bridge->isSimulated(),
            'digest_hex' => strtolower((string) $digest),
            'state_sha256' => (string) hash_file('sha256', $statePath),
            'pending_sha256' => (string) ($result['pending_sha256'] ?? ''),
            'pending_size' => (int) ($result['pending_size'] ?? 0),
            'certificate_fingerprint_sha256' => (string) ($certificate['cert_fingerprint_sha256'] ?? ''),
            'certificate_subject' => CertificateInspection::maskCpfIn(is_string($certificate['subject'] ?? null) ? $certificate['subject'] : null),
            'certificate_issuer' => is_string($certificate['issuer'] ?? null) ? $certificate['issuer'] : null,
            'certificate_serial' => is_string($certificate['serial_hex'] ?? null) ? $certificate['serial_hex'] : null,
            'certificate_not_before' => is_string($certificate['not_before'] ?? null) ? Carbon::parse($certificate['not_before']) : null,
            'certificate_not_after' => is_string($certificate['not_after'] ?? null) ? Carbon::parse($certificate['not_after']) : null,
            'is_test_certificate' => $isTest,
            // Fatos públicos, SEM o CPF completo (só mascarado).
            'certificate_facts' => [
                'holder_name' => is_string($holder['name'] ?? null) ? CertificateInspection::maskCpfIn($holder['name']) : null,
                'holder_cpf_masked' => is_string($holder['cpf_masked'] ?? null) ? $holder['cpf_masked'] : null,
                'subject_cn' => CertificateInspection::maskCpfIn(is_string($certificate['subject_cn'] ?? null) ? $certificate['subject_cn'] : null),
                'issuer_cn' => is_string($certificate['issuer_cn'] ?? null) ? $certificate['issuer_cn'] : null,
                'key_algorithm' => $certificate['key_algorithm'] ?? null,
                'key_size' => $certificate['key_size'] ?? null,
                'self_signed' => ($certificate['self_signed'] ?? false) === true,
                'policy_oids' => is_array($icp['policy_oids'] ?? null) ? array_values($icp['policy_oids']) : [],
                'declares_icp_brasil_policy' => ($icp['declares_icp_brasil_policy'] ?? false) === true,
                'declared_certificate_type' => is_string($icp['declared_certificate_type'] ?? null) ? $icp['declared_certificate_type'] : null,
                'icp_brasil_validated' => false,
                'chain_fingerprints_sha256' => is_array($certificate['chain_fingerprints_sha256'] ?? null) ? array_values($certificate['chain_fingerprints_sha256']) : [],
            ],
            'chain_trust' => [
                'trusted' => ($chainTrust['trusted'] ?? false) === true,
                'reason' => $chainTrust['reason'] ?? null,
                'anchors_pinned' => $trust['pinned'],
                'revocation' => 'not_checked',
            ],
            'reservation_key' => $document->getKey(),
            'expires_at' => $now->copy()->addMinutes(ExternalSigningFeature::ttlMinutes()),
            'attempts' => 0,
        ]);

        try {
            DB::transaction(fn () => $pending->save());
        } catch (QueryException) {
            // O índice único da reserva recusou: outra preparação entrou no mesmo instante.
            $this->files->delete($ulid);

            throw new ExternalSignatureException('document_reserved', 'Outro participante está assinando este documento agora. Tente de novo em alguns minutos.', 409, 'document_id');
        }

        $row->forceFill(['signing_component' => $bridge->component()->value])->save();

        SignerAudit::record($envelope, $context->recipient, AuditEventType::ParticipantCertificateSubmitted, [
            'request_ulid' => $row->ulid,
            'method' => self::METHOD,
            'component' => $bridge->component()->value,
            'simulated' => $bridge->isSimulated(),
            'pending_ulid' => $pending->ulid,
            'document_ulid' => $document->ulid,
            'mode' => $mode,
            'certificate_fingerprint_sha256' => $pending->certificate_fingerprint_sha256,
            'issuer_cn' => $pending->certificate_facts['issuer_cn'] ?? null,
            'serial' => $pending->certificate_serial,
            'is_test_certificate' => $isTest,
            'chain_trusted' => $pending->chain_trust['trusted'] ?? false,
            'expires_at' => $pending->expires_at->utc()->toIso8601String(),
        ], $correlationId);

        return $pending;
    }

    /**
     * @param  list<string>  $chainDers
     *
     * @throws ExternalSignatureException
     */
    private function embed(
        SignerContext $context,
        PendingExternalSignature $pending,
        string $mode,
        ?string $signature,
        ?string $certificate,
        array $chainDers,
        ?string $cms,
    ): void {
        if ($mode !== $pending->mode) {
            throw new ExternalSignatureException('mode_mismatch', 'O modo da assinatura enviada não é o da preparação.', 422, 'mode');
        }

        $this->assertConsumable($pending);

        $bridge = $this->bridges->for($pending->component);

        if (! $bridge->detect()->available) {
            throw new ExternalSignatureException('component_unavailable', 'Este componente de assinatura não está disponível neste ambiente.', 409, 'component');
        }

        $correlationId = (string) Str::ulid();
        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'ext-embed-');

        try {
            $inputs = ['signature' => null, 'certificate' => null, 'chain' => [], 'cms' => null];

            if ($signature !== null) {
                file_put_contents($inputs['signature'] = $workDir->path('assinatura.bin'), $signature);
                file_put_contents($inputs['certificate'] = $workDir->path('certificado.der'), (string) $certificate);

                foreach ($chainDers as $index => $der) {
                    file_put_contents($inputs['chain'][] = $workDir->path(sprintf('cadeia-%d.der', $index)), $der);
                }
            } else {
                file_put_contents($inputs['cms'] = $workDir->path('assinatura.p7s'), (string) $cms);
            }

            try {
                $applied = $this->lock->run(
                    (int) $pending->envelope_id,
                    fn (): bool => $this->embedLocked($context, $pending, $bridge, $inputs, $workDir, $correlationId),
                    ExternalSigningFeature::lockWaitSeconds(),
                );
            } catch (LockTimeoutException) {
                // Nada foi consumido: a reserva continua valendo até o prazo.
                throw $this->busy();
            }
        } finally {
            $workDir->delete();
        }

        if ($applied) {
            // Fora do lock: a finalização toma o mesmo lock para montar o arquivo final.
            $this->resumeFinalization($context->envelope);
        }
    }

    /**
     * @param  array{signature: string|null, certificate: string|null, chain: list<string>, cms: string|null}  $inputs
     * @return bool o pedido ficou completo (todos os documentos assinados)
     *
     * @throws ExternalSignatureException
     */
    private function embedLocked(
        SignerContext $context,
        PendingExternalSignature $pending,
        LocalSignerBridge $bridge,
        array $inputs,
        TemporaryDirectory $workDir,
        string $correlationId,
    ): bool {
        $now = Carbon::now();

        // Consumo ÚNICO: a reserva sai de `pending` uma vez só, e só dentro do prazo.
        $claimed = PendingExternalSignature::withoutOrganizationScope()
            ->whereKey($pending->getKey())
            ->where('status', PendingExternalSignatureStatus::Pending->value)
            ->where('expires_at', '>', $now)
            ->update([
                'status' => PendingExternalSignatureStatus::Embedding->value,
                'submitted_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        $pending->refresh();

        if ($claimed !== 1) {
            $this->assertConsumable($pending);

            throw $this->closedException($pending);
        }

        $envelope = Envelope::withoutOrganizationScope()->with('organization')->findOrFail($pending->envelope_id);
        $document = Document::withoutOrganizationScope()->findOrFail($pending->document_id);
        $row = ParticipantSignatureRequest::withoutOrganizationScope()->findOrFail($pending->participant_signature_request_id);

        try {
            if ($row->status !== ParticipantSignatureRequestStatus::Requested || $row->getAttribute('signature_method') !== self::METHOD) {
                throw new ExternalSignatureException('request_closed', 'Este pedido não está mais aberto para assinatura.', 409, 'pending_id');
            }

            if ($envelope->status !== EnvelopeStatus::Finalizing || $this->hasFinal($envelope)) {
                throw new ExternalSignatureException('envelope_closed', 'Este documento não está mais recebendo assinaturas com certificado.', 409, 'pending_id');
            }

            $base = $this->revisions->latest($document);

            // "Digest de outra revisão": outra assinatura entrou depois da preparação.
            if ($base === null || (int) $base->getKey() !== (int) $pending->base_document_version_id || ! hash_equals($pending->base_sha256, (string) $base->sha256)) {
                throw new ExternalSignatureException('stale_revision', ExternalSignatureLabels::rejection('stale_revision'), 409, 'pending_id');
            }

            if (! $this->files->matches($pending->ulid, PendingSignatureFiles::PENDING, $pending->pending_sha256)
                || ! $this->files->matches($pending->ulid, PendingSignatureFiles::STATE, $pending->state_sha256)) {
                throw new ExternalSignatureException('pending_missing', ExternalSignatureLabels::rejection('pending_missing'), 409, 'pending_id');
            }

            $output = $workDir->path('assinado.pdf');

            try {
                $result = $this->tool->embed(
                    $this->files->path($pending->ulid, PendingSignatureFiles::PENDING),
                    $this->files->path($pending->ulid, PendingSignatureFiles::STATE),
                    $output,
                    $inputs['signature'],
                    $inputs['certificate'],
                    $inputs['chain'],
                    $inputs['cms'],
                    $this->anchors->paths(),
                    $pending->certificate_fingerprint_sha256,
                    $correlationId,
                );
            } catch (PdfToolException $exception) {
                throw new ExternalSignatureException(
                    $exception->errorCode,
                    ExternalSignatureLabels::rejection($exception->errorCode),
                    $exception instanceof PdfToolProcessingException ? 500 : 422,
                    'signature',
                );
            }

            $kind = $this->kindFor($bridge, $pending);
            $acceptanceId = SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $pending->recipient_id)->value('id');

            try {
                [$version, $signatureRow] = $this->revisions->storeSigned(
                    $envelope,
                    $document,
                    $base,
                    $output,
                    fn (DocumentVersion $version): ParticipantSignature => $this->record($envelope, $document, $pending, $row, $base, $version, is_int($acceptanceId) ? $acceptanceId : null, $result, $kind),
                    $correlationId,
                );
            } catch (StaleRevisionException) {
                throw new ExternalSignatureException('stale_revision', ExternalSignatureLabels::rejection('stale_revision'), 409, 'pending_id');
            }
        } catch (ExternalSignatureException $exception) {
            $this->close($pending, PendingExternalSignatureStatus::Rejected, $exception->errorCode, $exception->getMessage());
            $this->rejected($context, $row, $exception->errorCode, $correlationId, $pending);

            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('Assinatura externa: falha inesperada ao incorporar.', [
                'pending_ulid' => $pending->ulid,
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);
            $this->close($pending, PendingExternalSignatureStatus::Rejected, 'embed_failed', ExternalSignatureLabels::rejection('embed_failed'));
            $this->rejected($context, $row, 'embed_failed', $correlationId, $pending);

            throw new ExternalSignatureException('embed_failed', ExternalSignatureLabels::rejection('embed_failed'), 500, 'signature');
        }

        $pending->forceFill([
            'status' => PendingExternalSignatureStatus::Applied,
            'consumed_at' => Carbon::now(),
            'closed_at' => Carbon::now(),
            'reservation_key' => null,
            'signed_document_version_id' => $version->getKey(),
            'failure_code' => null,
            'failure_message' => null,
        ])->save();
        $this->files->delete($pending->ulid);

        SignerAudit::system($envelope, AuditEventType::ParticipantSignatureApplied, [
            'request_ulid' => $row->ulid,
            'method' => self::METHOD,
            'signature_status' => $kind->value,
            'component' => $pending->component->value,
            'simulated' => $pending->is_simulated,
            'mode' => $pending->mode,
            'pending_ulid' => $pending->ulid,
            'document_ulid' => $document->ulid,
            'document_version_ulid' => $version->ulid,
            'sha256' => $version->sha256,
            'revision_index' => $signatureRow->revision_index,
            'field_name' => $signatureRow->field_name,
            'profile' => $signatureRow->profile,
            'certificate_fingerprint_sha256' => $signatureRow->fingerprint_sha256,
            'is_test_certificate' => $pending->is_test_certificate,
            'signature_count' => (int) ($result['signature_count'] ?? 0),
            'chain_ok' => (bool) ($result['chain']['ok'] ?? false),
            'chain_trusted' => (bool) ($result['trusted'] ?? false),
        ], $context->recipient, $correlationId);

        $complete = true;

        foreach (EnvelopeDocuments::sent($envelope) as $sent) {
            if (! $this->alreadySigned($row, $sent['document'])) {
                $complete = false;
            }
        }

        if ($complete) {
            $facts = $pending->certificate_facts ?? [];
            $row->forceFill([
                'status' => ParticipantSignatureRequestStatus::Applied,
                'applied_at' => Carbon::now(),
                'subject' => $pending->certificate_subject,
                'subject_cn' => $facts['subject_cn'] ?? null,
                'issuer' => $pending->certificate_issuer,
                'issuer_cn' => $facts['issuer_cn'] ?? null,
                'serial_number' => $pending->certificate_serial,
                'fingerprint_sha256' => $pending->certificate_fingerprint_sha256,
                'not_before' => $pending->certificate_not_before,
                'not_after' => $pending->certificate_not_after,
                'holder_cpf_masked' => $facts['holder_cpf_masked'] ?? null,
                'is_test_certificate' => $pending->is_test_certificate,
                'certificate_facts' => $facts + ['signature_status' => $kind->value, 'simulated' => $pending->is_simulated],
                'signing_component' => $pending->component->value,
                'failure_code' => null,
                'failure_message' => null,
            ])->save();
        }

        return $complete;
    }

    /**
     * `participant_a3` SÓ com componente real habilitado (não simulado) e certificado que declara
     * o tipo A3; em qualquer outro caso, `participant_external`.
     */
    private function kindFor(LocalSignerBridge $bridge, PendingExternalSignature $pending): ExternalSignatureKind
    {
        $declaresA3 = ($pending->certificate_facts['declared_certificate_type'] ?? null) === 'A3';

        return $bridge->producesTokenSignatures() && ! $bridge->isSimulated() && ! $pending->is_simulated && $declaresA3
            ? ExternalSignatureKind::ParticipantA3
            : ExternalSignatureKind::ParticipantExternal;
    }

    /**
     * @param  array<string, mixed>  $result  saída de `pdftool embed-external`
     */
    private function record(
        Envelope $envelope,
        Document $document,
        PendingExternalSignature $pending,
        ParticipantSignatureRequest $row,
        DocumentVersion $base,
        DocumentVersion $version,
        ?int $acceptanceId,
        array $result,
        ExternalSignatureKind $kind,
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
            'participant_signature_request_id' => $row->getKey(),
            'recipient_id' => $pending->recipient_id,
            'document_id' => $document->getKey(),
            'signature_acceptance_id' => $acceptanceId,
            'certificate_reference_id' => null,
            'base_document_version_id' => $base->getKey(),
            'signed_document_version_id' => $version->getKey(),
            'revision_index' => $this->revisions->signedRevisions($document)->count(),
            'field_name' => (string) ($result['field_name'] ?? $pending->field_name),
            'profile' => (string) ($result['profile'] ?? 'PAdES-B-B'),
            'subject' => $pending->certificate_subject,
            'issuer' => $pending->certificate_issuer,
            'serial_number' => $pending->certificate_serial,
            'fingerprint_sha256' => $pending->certificate_fingerprint_sha256,
            'not_before' => $pending->certificate_not_before,
            'not_after' => $pending->certificate_not_after,
            'signature_status' => $kind->value,
            'signing_component' => $pending->component->value,
            'is_simulated' => $pending->is_simulated,
            'pending_external_signature_id' => $pending->getKey(),
            'validation_result' => [
                'mode' => $pending->mode,
                'chain' => $result['chain'] ?? null,
                'previous_signature_count' => $result['previous_signature_count'] ?? null,
                'signature_count' => $result['signature_count'] ?? null,
                'prefix_preserved' => $result['prefix_preserved'] ?? null,
                'trusted' => ($result['trusted'] ?? false) === true,
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
     * @throws ExternalSignatureException
     */
    private function assertConsumable(PendingExternalSignature $pending): void
    {
        if ($pending->status === PendingExternalSignatureStatus::Pending && $pending->isExpired()) {
            $this->close($pending, PendingExternalSignatureStatus::Expired, 'expired', 'O prazo desta preparação terminou antes da assinatura. Prepare de novo.');
        }

        if ($pending->status !== PendingExternalSignatureStatus::Pending) {
            throw $this->closedException($pending);
        }
    }

    private function closedException(PendingExternalSignature $pending): ExternalSignatureException
    {
        return match ($pending->status) {
            PendingExternalSignatureStatus::Applied, PendingExternalSignatureStatus::Embedding => new ExternalSignatureException(
                'already_consumed', 'Esta preparação já foi usada e não pode ser usada de novo. Prepare de novo se precisar.', 409, 'pending_id',
            ),
            PendingExternalSignatureStatus::Expired => new ExternalSignatureException(
                'expired', 'O prazo desta preparação terminou. Prepare de novo para receber um novo resumo.', 409, 'pending_id',
            ),
            default => new ExternalSignatureException(
                'pending_closed', 'Esta preparação não vale mais. Prepare de novo.', 409, 'pending_id',
            ),
        };
    }

    private function close(PendingExternalSignature $pending, PendingExternalSignatureStatus $status, string $code, string $message): void
    {
        $pending->forceFill([
            'status' => $status,
            'reservation_key' => null,
            'closed_at' => Carbon::now(),
            'failure_code' => $status === PendingExternalSignatureStatus::Applied ? null : $code,
            'failure_message' => $status === PendingExternalSignatureStatus::Applied ? null : $message,
        ])->save();

        $this->files->delete($pending->ulid);
    }

    private function discardActive(ParticipantSignatureRequest $row, string $code): void
    {
        $rows = PendingExternalSignature::withoutOrganizationScope()
            ->where('participant_signature_request_id', $row->getKey())
            ->where('status', PendingExternalSignatureStatus::Pending->value)
            ->get();

        foreach ($rows as $pending) {
            $this->close($pending, PendingExternalSignatureStatus::Discarded, $code, 'Preparação descartada.');
        }
    }

    private function activeReservation(Document $document): ?PendingExternalSignature
    {
        /** @var PendingExternalSignature|null $pending */
        $pending = PendingExternalSignature::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->whereIn('status', [PendingExternalSignatureStatus::Pending->value, PendingExternalSignatureStatus::Embedding->value])
            ->orderByDesc('id')
            ->first();

        return $pending;
    }

    private function rejected(SignerContext $context, ParticipantSignatureRequest $row, string $code, string $correlationId, ?PendingExternalSignature $pending = null): void
    {
        $row->forceFill([
            'failure_code' => $code,
            'failure_message' => ExternalSignatureLabels::rejection($code),
        ])->save();

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ParticipantCertificateRejected, [
            'request_ulid' => $row->ulid,
            'method' => self::METHOD,
            'pending_ulid' => $pending?->ulid,
            'error_code' => $code,
        ], $correlationId);
    }

    /**
     * @throws ExternalSignatureException
     */
    private function findPending(SignerContext $context, string $ulid): PendingExternalSignature
    {
        /** @var PendingExternalSignature|null $pending */
        $pending = PendingExternalSignature::withoutOrganizationScope()
            ->where('ulid', $ulid)
            ->where('envelope_id', $context->envelope->getKey())
            ->where('recipient_id', $context->recipient->getKey())
            ->first();

        return $pending ?? throw new ExternalSignatureException('pending_not_found', 'Preparação não encontrada.', 404, 'pending_id');
    }

    /**
     * @throws ExternalSignatureException
     */
    private function sentDocument(Envelope $envelope, string $ulid): Document
    {
        foreach (EnvelopeDocuments::sent($envelope) as $row) {
            if ($row['document']->ulid === $ulid) {
                return $row['document'];
            }
        }

        throw new ExternalSignatureException('document_not_found', 'Documento não encontrado neste envio.', 404, 'document_id');
    }

    private function alreadySigned(ParticipantSignatureRequest $row, Document $document): bool
    {
        return ParticipantSignature::withoutOrganizationScope()
            ->where('participant_signature_request_id', $row->getKey())
            ->where('document_id', $document->getKey())
            ->exists();
    }

    private function findRequest(SignerContext $context): ?ParticipantSignatureRequest
    {
        /** @var ParticipantSignatureRequest|null $row */
        $row = ParticipantSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $context->envelope->getKey())
            ->where('recipient_id', $context->recipient->getKey())
            ->first();

        return $row;
    }

    /**
     * @return array{ready: bool, message: string|null}
     */
    private function readiness(SignerContext $context): array
    {
        if (! $context->hasSigned()) {
            return [
                'ready' => false,
                'message' => 'Registre primeiro o seu aceite eletrônico. A assinatura com o componente local é acrescentada depois que todos os participantes concluírem o aceite.',
            ];
        }

        if ($context->envelope->status !== EnvelopeStatus::Finalizing) {
            return [
                'ready' => false,
                'message' => $context->envelope->status === EnvelopeStatus::InProgress
                    ? 'Ainda faltam participantes concluírem o aceite. A sua escolha fica registrada: volte por este link quando todos tiverem concluído.'
                    : 'Este documento não está mais recebendo assinaturas com certificado.',
            ];
        }

        return ['ready' => true, 'message' => null];
    }

    private function isOpen(SignerContext $context): bool
    {
        return in_array($context->envelope->status, [EnvelopeStatus::InProgress, EnvelopeStatus::Finalizing], true)
            && ! $this->hasFinal($context->envelope);
    }

    private function hasFinal(Envelope $envelope): bool
    {
        $ids = array_map(static fn (array $row): int => (int) $row['document']->getKey(), EnvelopeDocuments::sent($envelope));

        return DocumentVersion::withoutOrganizationScope()
            ->whereIn('document_id', $ids)
            ->where('kind', DocumentVersionKind::Final->value)
            ->exists();
    }

    /**
     * @throws ExternalSignatureException
     */
    private function assertOpen(SignerContext $context): void
    {
        if (! $this->isOpen($context)) {
            throw new ExternalSignatureException('envelope_closed', 'Este documento não está mais recebendo assinaturas com certificado.', 409, 'method');
        }
    }

    /**
     * @throws ExternalSignatureException
     */
    private function assertAuthenticated(SignerContext $context, Request $request): void
    {
        if (! $this->authenticated($context, $request)) {
            throw new ExternalSignatureException('not_authenticated', 'Sua sessão expirou. Confirme o código enviado para você para continuar.', 403, 'method');
        }
    }

    private function busy(): ExternalSignatureException
    {
        return new ExternalSignatureException('busy', 'Outra assinatura está sendo incorporada a este documento agora. Tente de novo em alguns segundos.', 409, 'document_id');
    }

    /**
     * Mesma regra conservadora do A1 (docs/fase-2/a1-do-participante.md §11): se o certificado
     * declara um CPF e o participante informou um CPF neste envelope, os dois precisam bater.
     */
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
     * @throws ExternalSignatureException
     */
    private function decodeCertificate(string $value, string $field): string
    {
        $der = $this->decodeBinary($value, $this->maxCertificateKb() * 1024, $field, true);

        if (ExternalTrustAnchors::fingerprint($der) === null) {
            throw new ExternalSignatureException('certificate_invalid', ExternalSignatureLabels::rejection('certificate_invalid'), 422, $field);
        }

        return $der;
    }

    /**
     * Base64 (padrão ou URL) ou PEM → bytes. Nada do conteúdo vai para mensagem de erro.
     *
     * @throws ExternalSignatureException
     */
    private function decodeBinary(string $value, int $maxBytes, string $field, bool $allowPem = false): string
    {
        $value = trim($value);

        if ($allowPem && str_starts_with($value, '-----BEGIN')) {
            $value = (string) preg_replace('/-----(BEGIN|END)[^-]+-----|\s+/', '', $value);
        }

        $normalized = strtr((string) preg_replace('/\s+/', '', $value), '-_', '+/');
        $bytes = $normalized === '' ? false : base64_decode($normalized, true);

        if ($bytes === false || $bytes === '') {
            throw new ExternalSignatureException('invalid_encoding', 'O conteúdo enviado não está em Base64 válido.', 422, $field);
        }

        if (strlen($bytes) > $maxBytes) {
            throw new ExternalSignatureException('input_too_large', ExternalSignatureLabels::rejection('input_too_large'), 422, $field);
        }

        return $bytes;
    }

    private function maxCertificateKb(): int
    {
        return max(4, min(64, (int) $this->config->get('assinavelox.external_signing.max_certificate_kb', 32)));
    }

    private function maxCmsKb(): int
    {
        return max(8, min(64, (int) $this->config->get('assinavelox.external_signing.max_cms_kb', 48)));
    }

    private function maxChain(): int
    {
        return max(0, min(10, (int) $this->config->get('assinavelox.external_signing.max_chain_certificates', 6)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentsState(SignerContext $context, ?ParticipantSignatureRequest $row): array
    {
        $out = [];

        foreach (EnvelopeDocuments::sent($context->envelope) as $sent) {
            $document = $sent['document'];
            $signature = $row === null ? null : ParticipantSignature::withoutOrganizationScope()
                ->where('participant_signature_request_id', $row->getKey())
                ->where('document_id', $document->getKey())
                ->first();
            $active = $this->activeReservation($document);
            $live = $active !== null && ! $active->isExpired() ? $active : null;
            $mine = $live !== null && $row !== null && (int) $live->participant_signature_request_id === (int) $row->getKey() ? $live : null;
            $other = $live !== null && $mine === null ? $live : null;

            $status = match (true) {
                $signature !== null => 'signed',
                $mine !== null => 'reserved',
                $other !== null => 'busy',
                $this->revisions->latest($document) === null => 'waiting_base',
                default => 'to_sign',
            };

            $out[] = [
                'id' => $document->ulid,
                'name' => $document->name,
                'position' => (int) ($document->position ?? 1),
                'status' => $status,
                'signed_at' => $signature?->signed_at->toIso8601String(),
                'retry_after' => $signature === null && $other !== null ? $other->expires_at->utc()->toIso8601String() : null,
                'pending' => $signature === null && $mine !== null ? $this->pendingProps($context, $mine, false) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingProps(SignerContext $context, PendingExternalSignature $pending, bool $withDigest): array
    {
        $facts = $pending->certificate_facts ?? [];
        $trusted = ($pending->chain_trust['trusted'] ?? false) === true;
        $holder = is_string($facts['holder_name'] ?? null) ? $facts['holder_name'] : null;
        $issuer = is_string($facts['issuer_cn'] ?? null) ? $facts['issuer_cn'] : null;

        return [
            'id' => $pending->ulid,
            'document_id' => Document::withoutOrganizationScope()->whereKey($pending->document_id)->value('ulid'),
            'mode' => $pending->mode,
            'component' => $pending->component->value,
            'simulated' => $pending->is_simulated,
            'hash_function' => 'SHA256',
            // Só na resposta da preparação: é o que o componente assina (modo raw: resumo dos
            // atributos assinados, sem novo hash; modo cms: resumo do documento).
            'digest' => $withDigest ? base64_encode((string) hex2bin($pending->digest_hex)) : null,
            'digest_kind' => $pending->mode === PendingExternalSignature::MODE_RAW ? 'signed_attributes' : 'document',
            'expires_at' => $pending->expires_at->utc()->toIso8601String(),
            'certificate' => [
                'holder_name' => $holder,
                'holder_cpf_masked' => $facts['holder_cpf_masked'] ?? null,
                'issuer_cn' => $issuer,
                'serial' => $pending->certificate_serial,
                'fingerprint_sha256' => $pending->certificate_fingerprint_sha256,
                'valid_from' => $pending->certificate_not_before?->toIso8601String(),
                'valid_to' => $pending->certificate_not_after?->toIso8601String(),
                'is_test' => $pending->is_test_certificate,
                'kind_label' => ExternalSignatureLabels::certificate($facts, $pending->is_test_certificate),
            ],
            'chain' => [
                'trusted' => $trusted,
                'label' => ExternalSignatureLabels::chain($trusted, (int) ($pending->chain_trust['anchors_pinned'] ?? 0)),
                'revocation' => 'not_checked',
                'revocation_label' => ExternalSignatureLabels::REVOCATION_NOT_CHECKED,
            ],
            'endpoints' => [
                'submit' => $pending->is_simulated ? null : route('sign.external.submit', ['token' => $context->token]),
                'simulate' => $pending->is_simulated ? route('sign.external.simulator.sign', ['token' => $context->token]) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestProps(ParticipantSignatureRequest $row): array
    {
        $signatures = ParticipantSignature::withoutOrganizationScope()->where('participant_signature_request_id', $row->getKey())->get();
        $first = $signatures->first();
        $kind = ExternalSignatureKind::tryFrom((string) $first?->getAttribute('signature_status'));
        $simulated = (bool) $first?->getAttribute('is_simulated');

        return [
            'id' => $row->ulid,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'method' => self::METHOD,
            'component' => $row->getAttribute('signing_component'),
            'signature_status' => $kind?->value,
            'kind_label' => $kind === null ? null : ExternalSignatureLabels::kind($kind, $simulated),
            'label' => $kind === null || $row->status !== ParticipantSignatureRequestStatus::Applied ? null
                : ExternalSignatureLabels::signed($row->holderName(), $row->issuer_cn, $kind, $simulated, $row->is_test_certificate),
            'documents_signed' => $signatures->count(),
            'applied_at' => $row->applied_at?->toIso8601String(),
            'window_expires_at' => $row->window_expires_at?->toIso8601String(),
            'failure' => $row->failure_code === null ? null : ['code' => $row->failure_code, 'message' => $row->failure_message],
        ];
    }

    private function resumeFinalization(Envelope $envelope): void
    {
        $envelope = $envelope->refresh();

        if ($envelope->status !== EnvelopeStatus::Finalizing) {
            return;
        }

        try {
            FinalizeEnvelope::dispatch((int) $envelope->getKey(), (int) $envelope->organization_id, $envelope->finalization_key);
        } catch (Throwable $exception) {
            $this->logger->error('Assinatura externa: a retomada da finalização falhou; o envelope continua em finalizing.', [
                'envelope_ulid' => $envelope->ulid,
                'exception' => $exception::class,
                'alert' => 'envelope_finalization_dispatch_failed',
            ]);
        }
    }
}
