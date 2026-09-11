<?php

namespace App\Services\Signing\Certificates;

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Jobs\Envelopes\ApplyParticipantSignature;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Models\DocumentVersion;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\SigningFieldValue;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Pdf\Exceptions\PdfToolException;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Signing\Certificates\Exceptions\ParticipantCertificateException;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerDownloadGrants;
use App\Services\Signing\SignerRequestFacts;
use App\Services\Signing\SignerSessions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fluxo do participante que assina com o PRÓPRIO certificado A1 (Fase 2 §2.12).
 *
 * ## Quando a assinatura é aplicada — decisão
 *
 * **Aplicação ao final, com o material usado "na hora" ou num armazenamento cifrado de
 * prazo CURTO.** O conteúdo que o participante assina com o certificado é o arquivo que não
 * muda mais: consolidado + página de evidências (base `pre_signature`). Ele só existe quando
 * TODOS os aceites chegaram. Por isso:
 *
 * - antes disso, o participante só registra a ESCOLHA (`intent`) — nenhum PFX é aceito,
 *   nenhuma senha é guardada; guardar o PFX por dias até os outros assinarem seria custódia,
 *   que esta onda não implementa (docs/fase-2/a1-do-participante.md §6);
 * - quando o envelope entra em `finalizing`, a finalização prepara a base e ESPERA (com prazo)
 *   pelos pedidos; o participante volta pelo link (código → janela deste navegador), confere
 *   o certificado, consente e envia PFX + senha;
 * - o servidor inspeciona NA HORA e sela o conjunto (AES-256-GCM, chave derivada da APP_KEY,
 *   prazo de minutos) para o worker serializado do envelope, que o consome e o destrói. A
 *   senha nunca vai para a fila: o job carrega só o id do pedido.
 *
 * Quem assina antes dos demais, portanto, não deixa certificado nenhum na plataforma: deixa
 * a escolha registrada e volta quando o documento estiver pronto.
 *
 * ## Autenticação
 *
 * Sessão do código deste participante neste navegador (antes do aceite) ou a janela de
 * download aberta pelo aceite ou por um novo código (depois dele). A posse do link sozinha
 * não autoriza nada.
 */
final class ParticipantCertificateService
{
    public function __construct(
        private readonly ParticipantCertificateTool $tool,
        private readonly SealedCertificateStore $sealed,
        private readonly SignerSessions $sessions,
        private readonly SignerDownloadGrants $grants,
        private readonly PdfToolClient $client,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * O recurso existe para esta pessoa? (Senão, as rotas respondem 404.)
     */
    public function offeredTo(SignerContext $context): bool
    {
        return ParticipantA1Feature::enabledFor($context->organization)
            && ParticipantA1Feature::allowsRecipient($context->recipient)
            && in_array($context->state, [
                SignerContext::STATE_ACTIVE,
                SignerContext::STATE_SIGNED_PENDING_OTHERS,
                SignerContext::STATE_FINALIZING,
                SignerContext::STATE_COMPLETED,
            ], true);
    }

    /**
     * Quem JÁ registrou o aceite ainda pode enviar o certificado (envio aberto, pedido
     * inexistente, registrado ou com falha)? Nesse caso o código de confirmação pode ser pedido
     * de novo só para reabrir a janela de download ({@see SignerDownloadGrants}) — nunca uma
     * nova sessão de assinatura.
     */
    public function awaitsReturningSigner(SignerContext $context): bool
    {
        if (! $context->hasSigned() || ! $this->offeredTo($context) || ! $this->isOpen($context)) {
            return false;
        }

        $row = $this->findRequest($context);

        return $row === null || in_array($row->status, [
            ParticipantSignatureRequestStatus::Requested,
            ParticipantSignatureRequestStatus::Failed,
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
     * Estado para a página pública (contrato em docs/fase-2/a1-do-participante.md §7).
     *
     * @return array<string, mixed>
     */
    public function state(SignerContext $context, Request $request): array
    {
        $row = $this->findRequest($context);
        $authenticated = $this->authenticated($context, $request);
        $readiness = $this->readiness($context);
        $open = $this->isOpen($context);
        $status = $row?->status;

        $stage = match (true) {
            $row === null => $readiness['ready'] ? 'ready_to_upload' : ($open ? 'choose' : 'closed'),
            $status === ParticipantSignatureRequestStatus::Requested => $readiness['ready'] ? 'ready_to_upload' : 'awaiting_others',
            default => $status->value,
        };

        return [
            'available' => true,
            'authenticated' => $authenticated,
            'stage' => $stage,
            'ready' => $readiness['ready'],
            'message' => $readiness['message'],
            'can_request' => $authenticated && $open && ($row === null || $status === ParticipantSignatureRequestStatus::Withdrawn),
            'can_withdraw' => $authenticated && $row !== null && in_array($status, [
                ParticipantSignatureRequestStatus::Requested,
                ParticipantSignatureRequestStatus::Failed,
                ParticipantSignatureRequestStatus::Queued,
            ], true),
            'can_upload' => $authenticated && $open && $readiness['ready'] && ($row === null || in_array($status, [
                ParticipantSignatureRequestStatus::Requested,
                ParticipantSignatureRequestStatus::Failed,
            ], true)),
            'request' => $row === null ? null : $this->requestProps($row),
            'consent' => [
                'version' => ParticipantCertificateConsent::VERSION,
                'checkbox_label' => ParticipantCertificateConsent::CHECKBOX_LABEL,
                'summary' => ParticipantCertificateConsent::summary(),
                'legal_review_required' => ParticipantCertificateConsent::LEGAL_REVIEW_REQUIRED,
            ],
            'limits' => [
                'max_upload_kb' => max(8, (int) $this->config->get('assinavelox.participant_a1.max_upload_kb', 64)),
                'accepted_extensions' => ['pfx', 'p12'],
                'sealed_ttl_minutes' => $this->sealed->ttlMinutes(),
                'application_window_minutes' => max(1, (int) $this->config->get('assinavelox.participant_a1.application_window_minutes', 4320)),
            ],
            'notices' => [
                'O certificado e a senha são usados uma única vez e descartados logo em seguida; a plataforma não guarda cópia deles.',
                'A assinatura com certificado se soma ao seu aceite eletrônico e não o substitui.',
                'A plataforma confere senha, validade e uso do certificado, mas não valida a cadeia até uma raiz da ICP-Brasil nem consulta a revogação.',
            ],
            'endpoints' => [
                'show' => route('sign.certificate.show', ['token' => $context->token]),
                'intent' => route('sign.certificate.intent', ['token' => $context->token]),
                'withdraw' => route('sign.certificate.withdraw', ['token' => $context->token]),
                'inspect' => route('sign.certificate.inspect', ['token' => $context->token]),
                'store' => route('sign.certificate.store', ['token' => $context->token]),
            ],
        ];
    }

    /**
     * Registra a escolha "vou assinar também com o meu certificado". Idempotente.
     *
     * @throws ParticipantCertificateException
     */
    public function requestIntent(SignerContext $context, Request $request): ParticipantSignatureRequest
    {
        $this->assertAuthenticated($context, $request);
        $this->assertOpen($context);

        $row = $this->findRequest($context);

        if ($row !== null && $row->status->isActive()) {
            return $row;
        }

        if ($row !== null && $row->status === ParticipantSignatureRequestStatus::Expired) {
            throw new ParticipantCertificateException('window_closed', 'O prazo para assinar com o próprio certificado neste documento já terminou.', 409);
        }

        $row ??= new ParticipantSignatureRequest;
        $row->forceFill([
            'organization_id' => $context->envelope->organization_id,
            'envelope_id' => $context->envelope->getKey(),
            'recipient_id' => $context->recipient->getKey(),
            'status' => ParticipantSignatureRequestStatus::Requested,
            'failure_code' => null,
            'failure_message' => null,
            'closed_at' => null,
        ])->save();

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ParticipantCertificateRequested, [
            'request_ulid' => $row->ulid,
        ]);

        return $row;
    }

    /**
     * Desiste antes da aplicação. Se a finalização estava esperando só por este pedido, ela
     * é retomada.
     *
     * @throws ParticipantCertificateException
     */
    public function withdraw(SignerContext $context, Request $request): ParticipantSignatureRequest
    {
        $this->assertAuthenticated($context, $request);

        $row = $this->findRequest($context);

        if ($row === null) {
            throw new ParticipantCertificateException('not_requested', 'Você não optou por assinar com certificado neste documento.', 409);
        }

        if (! in_array($row->status, [ParticipantSignatureRequestStatus::Requested, ParticipantSignatureRequestStatus::Failed, ParticipantSignatureRequestStatus::Queued], true)) {
            throw new ParticipantCertificateException(
                'cannot_withdraw',
                $row->status === ParticipantSignatureRequestStatus::Applied
                    ? 'A assinatura com o seu certificado já foi aplicada.'
                    : 'Não é mais possível desistir neste momento.',
                409,
            );
        }

        $this->sealed->discard($row->sealed_ulid);
        $row->forceFill([
            'status' => ParticipantSignatureRequestStatus::Withdrawn,
            'sealed_ulid' => null,
            'sealed_expires_at' => null,
            'closed_at' => Carbon::now(),
        ])->save();

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ParticipantCertificateWithdrawn, [
            'request_ulid' => $row->ulid,
        ]);

        $this->resumeFinalization($context);

        return $row;
    }

    /**
     * Prévia do certificado (nada é guardado).
     *
     * @return array<string, mixed>
     *
     * @throws ParticipantCertificateException
     */
    public function preview(SignerContext $context, UploadedFile $file, #[\SensitiveParameter] string $password, Request $request): array
    {
        $this->assertAuthenticated($context, $request);

        [$inspection] = $this->inspectUpload($context, $file, $password, (string) Str::ulid(), false);
        $this->assertAcceptable($context, $inspection);

        return [
            'certificate' => $inspection->preview(),
            'holder_match' => $this->holderMatch($context, $inspection),
            'consent' => [
                'version' => ParticipantCertificateConsent::VERSION,
                'checkbox_label' => ParticipantCertificateConsent::CHECKBOX_LABEL,
                'statement' => ParticipantCertificateConsent::statement($context->envelope, $context->recipient, $inspection),
                'legal_review_required' => ParticipantCertificateConsent::LEGAL_REVIEW_REQUIRED,
            ],
            'ready' => $this->readiness($context)['ready'],
            'message' => $this->readiness($context)['message'],
        ];
    }

    /**
     * Consentimento + PFX + senha: confere na hora, sela por minutos e enfileira o worker do
     * envelope. Antes de o conteúdo congelar, só registra a escolha (nada é guardado).
     *
     * @throws ParticipantCertificateException
     */
    public function submit(
        SignerContext $context,
        UploadedFile $file,
        #[\SensitiveParameter] string $password,
        ?string $expectedFingerprint,
        Request $request,
    ): ParticipantSignatureRequest {
        $this->assertAuthenticated($context, $request);
        $this->assertOpen($context);

        $readiness = $this->readiness($context);

        if (! $readiness['ready']) {
            $this->discardUpload($file);
            $this->requestIntent($context, $request);

            throw new ParticipantCertificateException('not_ready', (string) $readiness['message'], 409);
        }

        $row = $this->findRequest($context);

        if ($row !== null && in_array($row->status, [ParticipantSignatureRequestStatus::Queued, ParticipantSignatureRequestStatus::Applying], true)) {
            $this->discardUpload($file);

            throw new ParticipantCertificateException('already_queued', 'O seu certificado já foi recebido e a assinatura está sendo aplicada.', 409);
        }

        if ($row !== null && $row->status === ParticipantSignatureRequestStatus::Applied) {
            $this->discardUpload($file);

            throw new ParticipantCertificateException('already_applied', 'A assinatura com o seu certificado já foi aplicada.', 409);
        }

        if ($row !== null && $row->status === ParticipantSignatureRequestStatus::Expired) {
            $this->discardUpload($file);

            throw new ParticipantCertificateException('window_closed', 'O prazo para assinar com o próprio certificado neste documento já terminou.', 409);
        }

        $correlationId = (string) Str::ulid();
        [$inspection, $pfxBytes] = $this->inspectUpload($context, $file, $password, $correlationId, true);
        $sealedUlid = null;

        try {
            $this->assertAcceptable($context, $inspection);

            if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $inspection->fingerprint)) {
                throw new ParticipantCertificateException('certificate_changed', 'O certificado enviado não é o mesmo da prévia. Confira o certificado e autorize de novo.');
            }

            $row = $this->prepareRow($context, $row);
            $this->assertSameCertificateForResume($row, $inspection);

            $sealedUlid = $this->sealed->seal($row->ulid, $pfxBytes, $password);
        } finally {
            $pfxBytes = str_repeat("\0", strlen($pfxBytes));
            unset($pfxBytes);
        }

        $now = Carbon::now();

        try {
            $row->forceFill([
                'status' => ParticipantSignatureRequestStatus::Queued,
                'consent_version' => ParticipantCertificateConsent::VERSION,
                'consent_statement' => ParticipantCertificateConsent::statement($context->envelope, $context->recipient, $inspection),
                'consented_at' => $now,
                'consent_ip' => SignerRequestFacts::ip($request),
                'subject' => CertificateInspection::maskCpfIn($inspection->subject),
                'subject_cn' => CertificateInspection::maskCpfIn($inspection->subjectCn),
                'issuer' => $inspection->issuer,
                'issuer_cn' => $inspection->issuerCn,
                'serial_number' => $inspection->serial,
                'fingerprint_sha256' => $inspection->fingerprint,
                'not_before' => $inspection->notBefore !== null ? Carbon::parse($inspection->notBefore) : null,
                'not_after' => $inspection->notAfter !== null ? Carbon::parse($inspection->notAfter) : null,
                'holder_cpf_masked' => $inspection->holderCpfMasked,
                'is_test_certificate' => $inspection->isTest,
                'certificate_facts' => $inspection->facts(),
                'sealed_ulid' => $sealedUlid,
                'sealed_expires_at' => $now->copy()->addMinutes($this->sealed->ttlMinutes()),
                'queued_at' => $now,
                'failure_code' => null,
                'failure_message' => null,
                'closed_at' => null,
            ])->save();
        } catch (Throwable $exception) {
            $this->sealed->discard($sealedUlid);

            throw $exception;
        }

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ParticipantCertificateSubmitted, [
            'request_ulid' => $row->ulid,
            'certificate_fingerprint_sha256' => $inspection->fingerprint,
            'issuer_cn' => $inspection->issuerCn,
            'serial' => $inspection->serial,
            'is_test_certificate' => $inspection->isTest,
            'consent_version' => ParticipantCertificateConsent::VERSION,
        ], $correlationId);

        $this->dispatchApplication($row, $correlationId);

        return $row->refresh();
    }

    /**
     * @return array{ready: bool, message: string|null}
     */
    public function readiness(SignerContext $context): array
    {
        if (! $context->hasSigned()) {
            return [
                'ready' => false,
                'message' => 'Registre primeiro o seu aceite eletrônico. A assinatura com o certificado é acrescentada depois que todos os participantes concluírem o aceite.',
            ];
        }

        if ($context->envelope->status !== EnvelopeStatus::Finalizing) {
            return [
                'ready' => false,
                'message' => $context->envelope->status === EnvelopeStatus::InProgress
                    ? 'Ainda faltam participantes concluírem o aceite. A sua escolha fica registrada: volte por este link quando todos tiverem concluído para enviar o certificado.'
                    : 'Este documento não está mais recebendo assinaturas com certificado.',
            ];
        }

        return ['ready' => true, 'message' => null];
    }

    private function isOpen(SignerContext $context): bool
    {
        if (! in_array($context->envelope->status, [EnvelopeStatus::InProgress, EnvelopeStatus::Finalizing], true)) {
            return false;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['document']->getKey(), EnvelopeDocuments::sent($context->envelope));

        return ! DocumentVersion::withoutOrganizationScope()
            ->whereIn('document_id', $ids)
            ->where('kind', DocumentVersionKind::Final->value)
            ->exists();
    }

    /**
     * @throws ParticipantCertificateException
     */
    private function assertOpen(SignerContext $context): void
    {
        if (! $this->isOpen($context)) {
            throw new ParticipantCertificateException('envelope_closed', 'Este documento não está mais recebendo assinaturas com certificado.', 409);
        }
    }

    /**
     * @throws ParticipantCertificateException
     */
    private function assertAuthenticated(SignerContext $context, Request $request): void
    {
        if (! $this->authenticated($context, $request)) {
            throw new ParticipantCertificateException('not_authenticated', 'Sua sessão expirou. Confirme o código enviado para você para continuar.', 403);
        }
    }

    /**
     * Regra de correspondência titular × participante (decisão pendente no roadmap §2.12;
     * esta onda adota a mais conservadora que não depende de cadastro externo): se o
     * certificado declara um CPF e o próprio participante informou um CPF neste envelope
     * (campo `cpf`), os dois precisam ser iguais. O nome é conferido e exibido, não bloqueia.
     * Certificado de teste só é aceito com `accept_test_certificates` ligado (desligado em
     * produção por padrão).
     *
     * @throws ParticipantCertificateException
     */
    private function assertAcceptable(SignerContext $context, CertificateInspection $inspection): void
    {
        if ($inspection->isTest && ! filter_var($this->config->get('assinavelox.participant_a1.accept_test_certificates', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new ParticipantCertificateException('test_certificate_not_accepted', 'Certificados de teste não são aceitos neste ambiente.');
        }

        if ($this->holderMatch($context, $inspection)['cpf'] === 'mismatch') {
            throw new ParticipantCertificateException('holder_mismatch', 'O CPF do certificado não corresponde ao CPF que você informou neste documento.');
        }
    }

    /**
     * @return array{cpf: 'match'|'mismatch'|'unknown', name: 'match'|'different'|'unknown', rule: string}
     */
    private function holderMatch(SignerContext $context, CertificateInspection $inspection): array
    {
        $informed = $this->informedCpf($context);
        $declared = $inspection->holderCpfDigits();

        $cpf = match (true) {
            $informed === null || $declared === null => 'unknown',
            hash_equals($informed, $declared) => 'match',
            default => 'mismatch',
        };

        $normalize = static fn (?string $name): string => trim((string) preg_replace('/\s+/', ' ', str_replace('TESTE', '', strtoupper(Str::ascii((string) $name)))));
        $holder = $normalize($inspection->holderName);
        $recipient = $normalize($context->recipient->name);

        return [
            'cpf' => $cpf,
            'name' => $holder === '' ? 'unknown' : ($holder === $recipient ? 'match' : 'different'),
            'rule' => 'Quando o certificado traz um CPF e você informou um CPF neste documento, os dois precisam ser iguais. O nome é apenas conferido e exibido.',
        ];
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
     * Copia o upload para um diretório temporário EXCLUSIVO, inspeciona e apaga tudo — o
     * diretório e o arquivo temporário do PHP — na mesma requisição, com sucesso ou falha.
     *
     * @return array{0: CertificateInspection, 1: string}
     *
     * @throws ParticipantCertificateException
     */
    private function inspectUpload(SignerContext $context, UploadedFile $file, #[\SensitiveParameter] string $password, string $correlationId, bool $keepBytes): array
    {
        $uploadPath = $file->getRealPath();
        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'a1-upload-');

        try {
            if (! $file->isValid() || $uploadPath === false || ! is_file($uploadPath)) {
                throw new ParticipantCertificateException('upload_failed', 'O arquivo do certificado não pôde ser recebido. Tente de novo.');
            }

            $bytes = (string) file_get_contents($uploadPath);
            $max = max(8, (int) $this->config->get('assinavelox.participant_a1.max_upload_kb', 64)) * 1024;

            if ($bytes === '' || strlen($bytes) > $max) {
                throw CertificateRejections::fromPdfTool($bytes === '' ? 'invalid_pkcs12' : 'pkcs12_too_large');
            }

            $pfxPath = $workDir->path('certificado.pfx');

            if (file_put_contents($pfxPath, $bytes, LOCK_EX) === false) {
                throw new ParticipantCertificateException('upload_failed', 'O arquivo do certificado não pôde ser preparado. Tente de novo.', 500);
            }

            try {
                $inspection = $this->tool->inspect($pfxPath, $password, $correlationId);
            } catch (PdfToolException $exception) {
                SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ParticipantCertificateRejected, [
                    'error_code' => $exception->errorCode,
                ], $correlationId);

                throw CertificateRejections::fromPdfTool($exception->errorCode);
            }

            return [$inspection, $keepBytes ? $bytes : ''];
        } finally {
            $workDir->delete();
            $this->discardUpload($file);
        }
    }

    private function discardUpload(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if ($path !== false && is_file($path)) {
            @unlink($path);
        }
    }

    private function prepareRow(SignerContext $context, ?ParticipantSignatureRequest $row): ParticipantSignatureRequest
    {
        return DB::transaction(function () use ($context, $row): ParticipantSignatureRequest {
            $row ??= new ParticipantSignatureRequest;

            if (! $row->exists) {
                $row->forceFill([
                    'organization_id' => $context->envelope->organization_id,
                    'envelope_id' => $context->envelope->getKey(),
                    'recipient_id' => $context->recipient->getKey(),
                    'status' => ParticipantSignatureRequestStatus::Requested,
                ])->save();

                SignerAudit::record($context->envelope, $context->recipient, AuditEventType::ParticipantCertificateRequested, [
                    'request_ulid' => $row->ulid,
                ]);
            }

            return $row;
        });
    }

    /**
     * Retomada depois de falha parcial (documento 1 assinado, documento 2 não): o certificado
     * precisa ser o mesmo já usado — o arquivo não pode terminar com dois titulares para o
     * mesmo participante.
     *
     * @throws ParticipantCertificateException
     */
    private function assertSameCertificateForResume(ParticipantSignatureRequest $row, CertificateInspection $inspection): void
    {
        $previous = ParticipantSignature::withoutOrganizationScope()
            ->where('participant_signature_request_id', $row->getKey())
            ->value('fingerprint_sha256');

        if (is_string($previous) && $previous !== '' && ! hash_equals($previous, $inspection->fingerprint)) {
            throw new ParticipantCertificateException('certificate_changed', 'Parte dos documentos já foi assinada com outro certificado. Envie o mesmo certificado usado antes.', 409);
        }
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
     * @return array<string, mixed>
     */
    private function requestProps(ParticipantSignatureRequest $row): array
    {
        return [
            'id' => $row->ulid,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'certificate' => $row->fingerprint_sha256 === null ? null : [
                'holder_name' => $row->holderName(),
                'holder_cpf_masked' => $row->holder_cpf_masked,
                'issuer_cn' => $row->issuer_cn,
                'serial' => $row->serial_number,
                'fingerprint_sha256' => $row->fingerprint_sha256,
                'valid_from' => $row->not_before?->toIso8601String(),
                'valid_to' => $row->not_after?->toIso8601String(),
                'is_test' => $row->is_test_certificate,
                'kind_label' => ParticipantSignatureViews::kindLabel($row),
                'label' => CertificateInspection::signedLabel($row->holderName(), $row->issuer_cn, $row->is_test_certificate),
            ],
            'consent_version' => $row->consent_version,
            'consented_at' => $row->consented_at?->toIso8601String(),
            'queued_at' => $row->queued_at?->toIso8601String(),
            'applied_at' => $row->applied_at?->toIso8601String(),
            'window_expires_at' => $row->window_expires_at?->toIso8601String(),
            'documents_signed' => ParticipantSignature::withoutOrganizationScope()->where('participant_signature_request_id', $row->getKey())->count(),
            'failure' => $row->failure_code === null ? null : [
                'code' => $row->failure_code,
                'message' => $row->failure_message,
            ],
        ];
    }

    private function dispatchApplication(ParticipantSignatureRequest $row, string $correlationId): void
    {
        try {
            ApplyParticipantSignature::dispatch((int) $row->getKey(), (int) $row->envelope_id, (int) $row->organization_id, $correlationId);
        } catch (Throwable $exception) {
            // Com o driver `sync` a aplicação roda dentro desta requisição; uma falha dela não
            // pode virar HTTP 500 para quem acabou de enviar o certificado. O pedido já ficou
            // marcado pelo próprio worker, e o estado da tela diz o que aconteceu.
            $this->logger->error('A1 do participante: falha ao despachar ou executar a aplicação.', [
                'request_ulid' => $row->ulid,
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);
        }
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
            $this->logger->error('A1 do participante: a retomada da finalização falhou; o envelope continua em finalizing.', [
                'envelope_ulid' => $envelope->ulid,
                'exception' => $exception::class,
                'alert' => 'envelope_finalization_dispatch_failed',
            ]);
        }
    }
}
