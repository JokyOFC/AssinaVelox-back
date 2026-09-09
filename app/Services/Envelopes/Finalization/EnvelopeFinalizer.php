<?php

namespace App\Services\Envelopes\Finalization;

use App\Enums\AuditEventType;
use App\Enums\CertificateEnvironment;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\SignatureStatus;
use App\Integrations\Pdf\PyHankoSigner;
use App\Models\AuditEvent;
use App\Models\CertificateReference;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Services\Documents\DocumentStorage;
use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Envelopes\Sending\CompletionNotifier;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Plans\PlanFeatures;
use App\Services\Plans\PlanLedger;
use App\Services\Signing\SignerAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pipeline de finalização (arquitetura §5, item 7).
 *
 * ```
 *  versão congelada (sent)
 *        │  a. compose  — campos AUTORIZADOS achatados + rodapé de verificação
 *        ▼
 *  DocumentVersion(consolidated) ── sha256 consolidado
 *        │  b. Blade → DOMPDF     — página de evidências (sem o hash final)
 *        ▼
 *  DocumentVersion(evidence)
 *        │  c. append             — consolidado + evidências
 *        ▼
 *  arquivo pré-assinatura (temporário)
 *        │  d. sign (PAdES B-B) + validate, SE houver certificado; senão, pula
 *        ▼
 *  DocumentVersion(final) ── sha256 final, calculado DEPOIS da assinatura
 *        │  f. VerificationRecord (4 hashes, signature_status, perfil, validação)
 *        ▼
 *  g. completed + trilha + consumo do plano + notificações
 * ```
 *
 * ## Idempotência e retomada
 *
 * Cada etapa pergunta antes se o artefato já existe (`FinalizationArtifacts::existing()`,
 * que exige linha no banco **e** bytes no disco). Uma segunda execução do job — por
 * retentativa, por worker duplicado ou por queda no meio — reaproveita o que já ficou
 * pronto, não duplica `document_versions` e não conclui duas vezes. O envelope só sai de
 * `finalizing` no último passo, sob lock, com arquivo final e registro de verificação já
 * persistidos.
 *
 * ## Transações
 *
 * **Nenhuma transação fica aberta durante uma chamada ao pdftool ou ao DOMPDF**
 * (arquitetura §3.3). Cada gravação é uma transação curta, entre chamadas.
 *
 * ## O que nunca acontece aqui
 *
 * - Concluir sem arquivo final validado e sem `verification_records`.
 * - Fingir assinatura criptográfica: sem certificado configurado a etapa (d) é pulada e o
 *   envelope conclui com `signature_status = none`, dito com todas as letras na interface e
 *   no PDF.
 * - Falha de assinatura concluir o envelope: a exceção sobe e o job falha.
 * - Imprimir o hash final dentro do próprio PDF: ele é calculado depois do arquivo pronto e
 *   publicado apenas em `verification_records`.
 */
class EnvelopeFinalizer
{
    public function __construct(
        private readonly PdfToolClient $client,
        private readonly DocumentStorage $storage,
        private readonly FinalizationArtifacts $artifacts,
        private readonly ConsolidationPlanner $planner,
        private readonly EvidenceData $evidenceData,
        private readonly EvidenceRenderer $evidenceRenderer,
        private readonly OperatorSignature $signature,
        private readonly PlanFeatures $planFeatures,
        private readonly PlanLedger $ledger,
        private readonly CompletionNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws FinalizationException
     */
    public function handle(int $envelopeId, int $organizationId, ?string $correlationId = null): FinalizationOutcome
    {
        $correlationId ??= (string) Str::ulid();

        $envelope = $this->load($envelopeId, $organizationId);

        if ($envelope === null) {
            return FinalizationOutcome::skipped('envelope_not_found');
        }

        if ($envelope->status === EnvelopeStatus::Completed) {
            return new FinalizationOutcome(
                'already_completed',
                $envelope,
                $envelope->finalVersion,
                $envelope->verificationRecord,
                $envelope->verificationRecord->signature_status ?? SignatureStatus::None,
            );
        }

        if ($envelope->status !== EnvelopeStatus::Finalizing) {
            // Recusado, expirado ou cancelado entre o disparo e o processamento. Não é erro:
            // é o estado do envelope, e ele manda.
            return FinalizationOutcome::skipped('not_finalizing', $envelope);
        }

        $document = $envelope->document;

        if ($document === null) {
            throw FinalizationException::missingDocument();
        }

        $sentVersion = $envelope->sentVersion;

        if ($sentVersion === null) {
            throw FinalizationException::missingSentVersion();
        }

        if ($envelope->verification_code === null) {
            throw FinalizationException::missingVerificationCode();
        }

        if (! $this->storage->exists($sentVersion)) {
            throw FinalizationException::sourceUnavailable();
        }

        $this->ensureFinalizationKey($envelope);

        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'finalize-');

        try {
            return $this->run($envelope, $document, $sentVersion, $workDir, $correlationId);
        } finally {
            $workDir->delete();
        }
    }

    /**
     * @throws FinalizationException
     */
    private function run(
        Envelope $envelope,
        Document $document,
        DocumentVersion $sentVersion,
        TemporaryDirectory $workDir,
        string $correlationId,
    ): FinalizationOutcome {
        $steps = [];

        // -- a. Consolidação ------------------------------------------------------------
        $consolidated = $this->artifacts->existing($document, DocumentVersionKind::Consolidated);
        $rebuilt = false;

        if ($consolidated === null) {
            $consolidated = $this->consolidate($envelope, $document, $sentVersion, $workDir, $correlationId);
            $steps['consolidated'] = 'created';
            $rebuilt = true;
        } else {
            $steps['consolidated'] = 'reused';
        }

        // -- b. Página de evidências ----------------------------------------------------
        /*
         * O certificado que ESTA execução vai usar, lido do PKCS#12 configurado — não a
         * linha mais recente de `certificate_references`. Ele governa duas coisas: o que a
         * página de evidências imprime e se uma página de execução anterior ainda serve.
         */
        $configuredCertificate = $this->signsFor($envelope)
            ? $this->signature->configuredCertificate($correlationId)
            : null;

        $variant = $this->evidenceVariant($envelope, $configuredCertificate);
        $evidence = $this->artifacts->existing($document, DocumentVersionKind::Evidence);

        /*
         * Reaproveitar a página de evidências exige que ela descreva ESTA execução. O bloco
         * "5. Sobre a assinatura criptográfica deste arquivo" é escrito antes de a assinatura
         * existir, a partir da configuração vigente; se a configuração mudou entre uma
         * tentativa e outra — a assinatura falhou e o operador desligou o certificado para
         * destravar o envelope, o certificado foi rotacionado, o ambiente mudou de teste para
         * produção —, a página gravada afirma algo que não vai acontecer. `reusableFinal()`
         * já fazia esta conferência para o arquivo `final`; a etapa (b) não fazia nenhuma, e
         * o resultado era um PDF sem assinatura nenhuma carregando dentro de si a frase
         * "este arquivo recebeu uma assinatura digital". Uma consolidação refeita também
         * invalida a página: os hashes impressos nela são os daquele consolidado.
         */
        if ($evidence !== null && ($rebuilt || ! $this->evidenceMatchesVariant($envelope, $evidence, $variant))) {
            $this->artifacts->discard($evidence, $rebuilt
                ? 'o documento consolidado foi refeito nesta execução'
                : 'a página de evidências descreve outra configuração de assinatura');

            $evidence = null;
        }

        if ($evidence === null) {
            $evidence = $this->generateEvidence(
                $envelope, $document, $sentVersion, $consolidated, $configuredCertificate, $variant, $workDir, $correlationId
            );
            $steps['evidence'] = 'created';
            $rebuilt = true;
        } else {
            $steps['evidence'] = 'reused';
        }

        // -- c/d/e. Junção, assinatura e versão final -----------------------------------
        $final = $this->reusableFinal($envelope, $document, $workDir, $correlationId);

        if ($final !== null && $rebuilt) {
            // O `final` embute a página de evidências e o consolidado que foram refeitos.
            $this->artifacts->discard($final, 'o arquivo final embute artefatos refeitos nesta execução');

            $final = null;
        }

        if ($final === null) {
            [$final, $signatureStatus, $profile, $validation, $certificate] =
                $this->buildFinal($envelope, $document, $consolidated, $evidence, $workDir, $correlationId);

            $steps['final'] = 'created';
            $steps['signature'] = $signatureStatus === SignatureStatus::CompanyA1 ? 'created' : 'skipped';
        } else {
            [$signatureStatus, $profile, $validation, $certificate] = $this->recoverSignatureState($envelope, $final, $workDir, $correlationId);
            $steps['final'] = 'reused';
            $steps['signature'] = 'reused';
        }

        // -- f. Registro de verificação --------------------------------------------------
        $record = $this->verificationRecord(
            $envelope,
            $document,
            $sentVersion,
            $consolidated,
            $final,
            $signatureStatus,
            $profile,
            $validation,
            $certificate,
            $steps,
        );

        // -- g. Conclusão ----------------------------------------------------------------
        $completed = $this->complete($envelope, $final, $record, $signatureStatus, $correlationId, $steps);

        return new FinalizationOutcome(
            $completed ? 'completed' : 'already_completed',
            $envelope->refresh(),
            $final,
            $record,
            $signatureStatus,
            $steps,
        );
    }

    /**
     * Este envelope recebe a assinatura criptográfica da operadora?
     *
     * Duas condições, e as duas precisam valer:
     *
     * 1. **há certificado configurado** — `OperatorSignature::isConfigured()`. Sem ele nada
     *    é assinado, em plano nenhum, e o envelope conclui como aceite eletrônico com
     *    evidências (arquitetura §2);
     * 2. **o plano da organização inclui o item** — `plans.features.company_signature`. A
     *    flag existe desde o `PlanSeeder` (false no Grátis, true nos pagos) e é anunciada na
     *    comparação de planos; antes desta correção nada a lia, então o Grátis receberia a
     *    assinatura no instante em que a operadora configurasse o certificado, e a tabela de
     *    preços dizia o contrário.
     *
     * A decisão é tomada uma vez por etapa e vale para todas: o que a página de evidências
     * afirma, o que o arquivo final recebe e o que um `final` reaproveitado precisa conter.
     */
    private function signsFor(Envelope $envelope): bool
    {
        return $this->signature->isConfigured() && $this->planFeatures->allowsForEnvelope($envelope);
    }

    // -- Etapa a ---------------------------------------------------------------------

    private function consolidate(
        Envelope $envelope,
        Document $document,
        DocumentVersion $sentVersion,
        TemporaryDirectory $workDir,
        string $correlationId,
    ): DocumentVersion {
        $source = $this->artifacts->copyToTemporary($sentVersion, $workDir, 'enviado.pdf');
        $output = $workDir->path('consolidado.pdf');

        ['plan' => $plan, 'fields' => $fields, 'footers' => $footers] =
            $this->planner->build($envelope, $sentVersion, $source, $workDir);

        $result = $this->client->compose($plan, $output, $correlationId);

        $version = $this->artifacts->store($envelope, $document, DocumentVersionKind::Consolidated, $output, $correlationId);

        SignerAudit::system($envelope, AuditEventType::EnvelopeConsolidated, [
            'document_version_ulid' => $version->ulid,
            'sha256' => $version->sha256,
            'page_count' => $result->pageCount,
            'fields_drawn' => $result->fieldsDrawn,
            'fields_planned' => $fields,
            'footers' => $footers,
            'skipped' => $result->skipped,
        ], null, $correlationId);

        return $version;
    }

    // -- Etapa b ---------------------------------------------------------------------

    /**
     * @param  array{signature_status: string, certificate_fingerprint_sha256: string|null}  $variant
     */
    private function generateEvidence(
        Envelope $envelope,
        Document $document,
        DocumentVersion $sentVersion,
        DocumentVersion $consolidated,
        ?CertificateReference $certificate,
        array $variant,
        TemporaryDirectory $workDir,
        string $correlationId,
    ): DocumentVersion {
        // A variante do bloco de assinatura descreve o que ESTA finalização vai fazer: se o
        // adaptador está configurado, a assinatura será aplicada logo adiante; senão, o
        // envelope conclui como aceite eletrônico com evidências. Nada é prometido no
        // condicional — e a variante gravada na trilha permite conferir, numa retomada, se a
        // página continua descrevendo a execução que está acontecendo.
        $willSign = $variant['signature_status'] === SignatureStatus::CompanyA1->value;

        $data = $this->evidenceData->build(
            envelope: $envelope,
            sentVersion: $sentVersion,
            hashes: [
                'original' => $this->originalSha256($document),
                'sent' => $sentVersion->sha256,
                'consolidated' => $consolidated->sha256,
            ],
            signatureStatus: $willSign ? SignatureStatus::CompanyA1 : SignatureStatus::None,
            signatureProfile: $willSign ? PyHankoSigner::PROFILE : null,
            certificate: $willSign ? $certificate : null,
            validationResult: null,
            generatedAt: Carbon::now(),
        );

        $output = $this->evidenceRenderer->render($data, $workDir->path('evidencias.pdf'));

        $version = $this->artifacts->store($envelope, $document, DocumentVersionKind::Evidence, $output, $correlationId);

        SignerAudit::system($envelope, AuditEventType::EnvelopeEvidenceGenerated, [
            'document_version_ulid' => $version->ulid,
            'sha256' => $version->sha256,
            'page_count' => $version->page_count,
            'participants' => count($data['participants']),
            'timeline_events' => count($data['timeline']),
            // Marca da variante impressa (ver evidenceMatchesVariant()). Impressão digital de
            // certificado é dado público — não é segredo, não é a chave e não é a senha.
            'signature_status' => $variant['signature_status'],
            'certificate_fingerprint_sha256' => $variant['certificate_fingerprint_sha256'],
        ], null, $correlationId);

        return $version;
    }

    /**
     * O que a página de evidências vai afirmar sobre a assinatura desta execução.
     *
     * @return array{signature_status: string, certificate_fingerprint_sha256: string|null}
     */
    private function evidenceVariant(Envelope $envelope, ?CertificateReference $certificate): array
    {
        $willSign = $this->signsFor($envelope);

        return [
            'signature_status' => ($willSign ? SignatureStatus::CompanyA1 : SignatureStatus::None)->value,
            'certificate_fingerprint_sha256' => $willSign ? $certificate?->fingerprint_sha256 : null,
        ];
    }

    /**
     * A página de evidências gravada descreve a mesma variante desta execução?
     *
     * A marca fica no evento `envelope.evidence_generated` da trilha — append-only, nunca
     * reescrita, e já o registro de quando cada artefato nasceu. Marca ausente (página de uma
     * versão anterior do pipeline) conta como divergência: regerar custa um render; publicar
     * uma afirmação de assinatura que não corresponde ao arquivo não tem conserto.
     *
     * @param  array{signature_status: string, certificate_fingerprint_sha256: string|null}  $variant
     */
    private function evidenceMatchesVariant(Envelope $envelope, DocumentVersion $evidence, array $variant): bool
    {
        $events = AuditEvent::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('event_type', AuditEventType::EnvelopeEvidenceGenerated->value)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];

            if (($payload['document_version_ulid'] ?? null) !== $evidence->ulid) {
                continue;
            }

            return ($payload['signature_status'] ?? null) === $variant['signature_status']
                && ($payload['certificate_fingerprint_sha256'] ?? null) === $variant['certificate_fingerprint_sha256'];
        }

        return false;
    }

    // -- Etapas c, d, e ---------------------------------------------------------------

    /**
     * @return array{0: DocumentVersion, 1: SignatureStatus, 2: string|null, 3: array<string, mixed>, 4: CertificateReference|null}
     *
     * @throws FinalizationException
     */
    private function buildFinal(
        Envelope $envelope,
        Document $document,
        DocumentVersion $consolidated,
        DocumentVersion $evidence,
        TemporaryDirectory $workDir,
        string $correlationId,
    ): array {
        $base = $this->artifacts->copyToTemporary($consolidated, $workDir, 'base.pdf');
        $extra = $this->artifacts->copyToTemporary($evidence, $workDir, 'evidencias-anexo.pdf');
        $preSignature = $workDir->path('pre-assinatura.pdf');

        $this->client->append($base, $extra, $preSignature, $correlationId);

        $finalPath = $workDir->path('final.pdf');

        if ($this->signsFor($envelope)) {
            $signed = $this->signature->signAndValidate($preSignature, $finalPath, $correlationId);

            /** @var ValidationResult $validation */
            $validation = $signed['validation'];
            /** @var CertificateReference|null $certificate */
            $certificate = $signed['certificate'];

            $payload = $this->signature->validationPayload(
                SignatureStatus::CompanyA1,
                $validation,
                $signed['profile'],
                $certificate->environment ?? CertificateEnvironment::Test,
            );

            $version = $this->artifacts->store($envelope, $document, DocumentVersionKind::Final, $finalPath, $correlationId);

            SignerAudit::system($envelope, AuditEventType::EnvelopeSignedCompanyA1, [
                'document_version_ulid' => $version->ulid,
                'profile' => $signed['profile'],
                'field_name' => $signed['result']->fieldName,
                'certificate_fingerprint_sha256' => $signed['result']->certFingerprintSha256,
                'certificate_environment' => ($certificate->environment ?? CertificateEnvironment::Test)->value,
                'timestamp' => null,
                'signature_count' => $validation->signatureCount,
                'all_intact' => $validation->allIntact,
                'all_valid' => $validation->allValid,
                'all_trusted' => $validation->allTrusted(),
                'revocation' => $validation->revocation,
            ], null, $correlationId);

            return [$version, SignatureStatus::CompanyA1, $signed['profile'], $payload, $certificate];
        }

        // Sem certificado: o arquivo pré-assinatura É o final. Nenhum campo de assinatura,
        // nenhuma afirmação de assinatura criptográfica.
        if (! @copy($preSignature, $finalPath)) {
            throw FinalizationException::writeFailed('final', ['reason' => 'copy_failed']);
        }

        $version = $this->artifacts->store($envelope, $document, DocumentVersionKind::Final, $finalPath, $correlationId);

        $payload = $this->signature->validationPayload(
            SignatureStatus::None,
            null,
            null,
            null,
            'signer_not_configured',
        );

        return [$version, SignatureStatus::None, null, $payload, null];
    }

    /**
     * Versão final reaproveitável de uma execução anterior.
     *
     * Um `final` só serve se for **coerente com a configuração atual de assinatura**. Um
     * arquivo produzido sem certificado enquanto agora existe um (ou o inverso) descreve
     * uma finalização que não é a que está acontecendo: como ele nunca foi publicado — o
     * envelope não concluiu e não há registro de verificação —, é descartado e refeito.
     */
    private function reusableFinal(Envelope $envelope, Document $document, TemporaryDirectory $workDir, string $correlationId): ?DocumentVersion
    {
        $final = $this->artifacts->existing($document, DocumentVersionKind::Final);

        if ($final === null) {
            return null;
        }

        $local = $this->artifacts->copyToTemporary($final, $workDir, 'final-existente.pdf');
        $inspection = $this->client->inspect($local, $correlationId);

        if ($inspection->hasSignatures !== $this->signsFor($envelope)) {
            $this->artifacts->discard($final, $inspection->hasSignatures
                ? 'arquivo assinado, mas a assinatura da operadora não está mais configurada'
                : 'arquivo sem assinatura, mas o certificado da operadora está configurado');

            return null;
        }

        return $final;
    }

    /**
     * Estado de assinatura de um `final` reaproveitado — derivado dos **bytes**, não de uma
     * suposição sobre a execução anterior.
     *
     * @return array{0: SignatureStatus, 1: string|null, 2: array<string, mixed>, 3: CertificateReference|null}
     */
    private function recoverSignatureState(Envelope $envelope, DocumentVersion $final, TemporaryDirectory $workDir, string $correlationId): array
    {
        if (! $final->has_signatures && ! $this->signsFor($envelope)) {
            return [
                SignatureStatus::None,
                null,
                $this->signature->validationPayload(SignatureStatus::None, null, null, null, 'signer_not_configured'),
                null,
            ];
        }

        $local = $workDir->path('final-existente.pdf');

        if (! is_file($local)) {
            $local = $this->artifacts->copyToTemporary($final, $workDir, 'final-existente.pdf');
        }

        $validation = $this->signature->validate($local);

        // Mesmas quatro condições da assinatura recém-aplicada, inclusive a cobertura do
        // arquivo inteiro: um `final` de execução anterior que ganhou bytes fora da revisão
        // assinada continua `intact`/`valid` e não pode ser republicado como assinado.
        OperatorSignature::assertPublishable($validation);

        $fingerprint = $validation->signatures[0]->certFingerprintSha256;

        /** @var CertificateReference|null $certificate */
        $certificate = $fingerprint === null || $fingerprint === ''
            ? null
            : CertificateReference::query()->where('fingerprint_sha256', $fingerprint)->first();

        $profile = PyHankoSigner::PROFILE;

        return [
            SignatureStatus::CompanyA1,
            $profile,
            $this->signature->validationPayload(
                SignatureStatus::CompanyA1,
                $validation,
                $profile,
                $certificate->environment ?? CertificateEnvironment::Test,
            ),
            $certificate,
        ];
    }

    // -- Etapa f ----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $validationPayload
     * @param  array<string, string>  $steps
     */
    private function verificationRecord(
        Envelope $envelope,
        Document $document,
        DocumentVersion $sentVersion,
        DocumentVersion $consolidated,
        DocumentVersion $final,
        SignatureStatus $signatureStatus,
        ?string $profile,
        array $validationPayload,
        ?CertificateReference $certificate,
        array &$steps,
    ): VerificationRecord {
        $existing = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->first();

        $attributes = [
            'code' => (string) $envelope->verification_code,
            'envelope_id' => $envelope->getKey(),
            'organization_id' => $envelope->organization_id,
            'final_document_version_id' => $final->getKey(),
            'original_sha256' => $this->originalSha256($document),
            'sent_sha256' => $sentVersion->sha256,
            'consolidated_sha256' => $consolidated->sha256,
            // O hash final é dos bytes DEPOIS da assinatura e mora aqui, fora do PDF.
            'final_sha256' => $final->sha256,
            'signature_status' => $signatureStatus,
            'signature_profile' => $signatureStatus === SignatureStatus::CompanyA1 ? $profile : null,
            'certificate_reference_id' => $certificate?->getKey(),
            'validation_result' => $validationPayload,
            'validated_at' => Carbon::now(),
        ];

        if ($existing !== null) {
            /*
             * Um registro existente só serve se descrever o arquivo final DESTA execução.
             *
             * A queda entre a etapa f (registro commitado) e a etapa g (envelope concluído)
             * deixa o envelope em `finalizing` com o registro já gravado. Se, no intervalo, o
             * certificado da operadora sair do ar (venceu, foi revogado, a variável sumiu num
             * deploy), a retentativa reconstrói o `final` SEM assinatura — e reaproveitar o
             * registro antigo publicaria `signature_status = company_a1`,
             * `signature_profile = PAdES-B-B` e o `final_sha256` de um arquivo descartado
             * sobre um PDF que não tem assinatura nenhuma. A página pública afirmaria
             * assinatura criptográfica onde não há (arquitetura §2) e a conferência por
             * resumo diria "Não confere" para o arquivo verdadeiro. Vale sempre a execução
             * corrente: quem publica é ela.
             */
            if ($this->recordMatches($existing, $attributes)) {
                $steps['verification_record'] = 'reused';

                return $existing;
            }

            $steps['verification_record'] = 'rewritten';

            $this->logger->warning('Finalização: registro de verificação de outra execução foi reescrito.', [
                'envelope_ulid' => $envelope->ulid,
                'previous_final_document_version_id' => $existing->final_document_version_id,
                'previous_signature_status' => $existing->signature_status->value,
                'final_document_version_ulid' => $final->ulid,
                'signature_status' => $signatureStatus->value,
            ]);

            return DB::transaction(function () use ($existing, $attributes): VerificationRecord {
                $existing->forceFill($attributes)->save();

                return $existing;
            });
        }

        $steps['verification_record'] = 'created';

        return DB::transaction(function () use ($attributes): VerificationRecord {
            /** @var VerificationRecord $record */
            $record = VerificationRecord::query()->create($attributes);

            return $record;
        });
    }

    /**
     * O registro já gravado descreve exatamente o desfecho desta execução?
     *
     * Compara o que é publicado: a versão final apontada, o resumo dela e o que se afirma
     * sobre a assinatura. Datas e o resultado bruto da validação ficam de fora — eles mudam
     * a cada execução sem que o desfecho mude.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordMatches(VerificationRecord $record, array $attributes): bool
    {
        foreach (['final_document_version_id', 'final_sha256', 'consolidated_sha256', 'sent_sha256'] as $key) {
            if ($record->{$key} !== $attributes[$key]) {
                return false;
            }
        }

        return ($record->signature_status ?? SignatureStatus::None) === $attributes['signature_status']
            && $record->signature_profile === $attributes['signature_profile']
            && $record->certificate_reference_id === $attributes['certificate_reference_id'];
    }

    // -- Etapa g ----------------------------------------------------------------------

    /**
     * Transição para `completed` sob lock, com tudo já persistido. Devolve false quando
     * outro processo concluiu antes (idempotência).
     *
     * @param  array<string, string>  $steps
     */
    private function complete(
        Envelope $envelope,
        DocumentVersion $final,
        VerificationRecord $record,
        SignatureStatus $signatureStatus,
        string $correlationId,
        array &$steps,
    ): bool {
        $completed = DB::transaction(function () use ($envelope, $final, $record, $signatureStatus, $correlationId): bool {
            /** @var Envelope|null $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== EnvelopeStatus::Finalizing) {
                return false;
            }

            $locked->transitionTo(EnvelopeStatus::Completed);
            $locked->forceFill([
                'final_document_version_id' => $final->getKey(),
                'completed_at' => $locked->completed_at ?? Carbon::now(),
            ])->save();

            SignerAudit::system($locked, AuditEventType::EnvelopeCompleted, [
                'final_document_version_ulid' => $final->ulid,
                'final_sha256' => $final->sha256,
                'signature_status' => $signatureStatus->value,
                'signature_profile' => $record->signature_profile,
                'verification_code' => $locked->verification_code,
            ], null, $correlationId);

            return true;
        });

        $steps['completed'] = $completed ? 'created' : 'reused';

        if (! $completed) {
            return false;
        }

        $envelope->refresh();

        // Consumo do plano: já foi confirmado no envio; `commit()` é idempotente e existe
        // aqui para o caso de uma reserva ainda aberta. Nunca cobra duas vezes.
        $consumption = $this->ledger->forEnvelopeSend($envelope);

        if ($consumption !== null) {
            $this->ledger->commit($consumption, $envelope);
        }

        // Notificações e link de download autorizado (serviço do incremento 3).
        try {
            $this->notifier->notify($envelope);
        } catch (Throwable $exception) {
            // O envelope está concluído e o arquivo existe. Falhar o job aqui faria a
            // finalização inteira ser repetida por causa de um e-mail.
            $this->logger->error('Finalização: envelope concluído, mas as notificações falharam.', [
                'envelope_ulid' => $envelope->ulid,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 300, ''),
                'correlation_id' => $correlationId,
            ]);
        }

        return true;
    }

    // -- Apoio -------------------------------------------------------------------------

    private function load(int $envelopeId, int $organizationId): ?Envelope
    {
        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()
            ->with(['organization', 'document', 'sentVersion', 'finalVersion', 'verificationRecord'])
            ->whereKey($envelopeId)
            ->where('organization_id', $organizationId)
            ->first();

        return $envelope;
    }

    private function ensureFinalizationKey(Envelope $envelope): void
    {
        if ($envelope->finalization_key !== null && $envelope->finalization_key !== '') {
            return;
        }

        $envelope->forceFill(['finalization_key' => (string) Str::ulid()])->save();
    }

    private function originalSha256(Document $document): ?string
    {
        /** @var string|null $sha */
        $sha = $document->versions()
            ->withoutGlobalScopes()
            ->where('kind', DocumentVersionKind::Original->value)
            ->orderBy('version_number')
            ->value('sha256');

        return $sha;
    }
}
