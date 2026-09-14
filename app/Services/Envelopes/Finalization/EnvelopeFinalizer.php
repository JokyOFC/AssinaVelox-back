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
use App\Models\VerificationRecordDocument;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Envelopes\Sending\CompletionNotifier;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Plans\PlanFeatures;
use App\Services\Plans\PlanLedger;
use App\Services\Signing\Certificates\IncrementalChain;
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
        private readonly ParticipantSignatureStage $participants,
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

        // Fase 2 §2.3: vários documentos — o pipeline roda POR DOCUMENTO, sob a mesma
        // `finalization_key`, e o envelope só conclui quando todos estão finais.
        $sent = EnvelopeDocuments::sent($envelope);

        if (count($sent) > 1) {
            return $this->handleMulti($envelope, $sent, $correlationId);
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

        // -- Fase 2 §2.12: assinaturas com o certificado A1 dos próprios participantes ------
        // Com pedidos de participantes, o arquivo final é montado em revisões incrementais
        // (base congelada → participantes, um por vez → operadora por último). Sem pedidos,
        // nada abaixo muda: é o pipeline da Fase 1.
        if ($this->participants->activeFor($envelope)) {
            return $this->participantsPipeline(
                $envelope,
                [['document' => $document, 'version' => $sentVersion]],
                [$consolidated],
                [$evidence],
                [$rebuilt],
                $correlationId,
                $steps,
            );
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

        // Resumos também na tabela filha (Fase 2 §2.3): a verificação pública lê os
        // documentos de um lugar só, com um ou com vários arquivos.
        $this->verificationDocuments($record, [['document' => $document, 'version' => $sentVersion]], [$consolidated], [['final' => $final]]);

        // -- g. Conclusão ----------------------------------------------------------------
        $completed = $this->complete($envelope, $final, $record, $signatureStatus, $correlationId, $steps, [
            (int) $document->getKey() => (int) $final->getKey(),
        ]);

        return new FinalizationOutcome(
            $completed ? 'completed' : 'already_completed',
            $envelope->refresh(),
            $final,
            $record,
            $signatureStatus,
            $steps,
        );
    }

    // -- Vários documentos (Fase 2 §2.3) --------------------------------------------------

    /**
     * Pipeline para N documentos.
     *
     * Mesmas etapas e mesmas garantias do caminho de um documento, aplicadas por documento:
     *
     * a. consolida CADA documento (reaproveitando o que já existe no disco com o hash certo);
     * b. gera a página de evidências de CADA documento — ela lista todos os documentos do
     *    envelope com seus resumos e quem aceitou cada um. Por listar os resumos consolidados
     *    de todos, qualquer consolidação refeita invalida TODAS as páginas; a marca
     *    `documents_digest` na trilha garante a mesma coisa numa retomada;
     * c/d/e. junta, assina (quando configurado) e valida o arquivo final de CADA documento;
     * f. um `verification_records` para o envelope (colunas = primeiro documento) e uma linha
     *    em `verification_record_documents` por documento;
     * g. `completed` só depois de TODOS os finais existirem, sob lock.
     *
     * Retomável por documento: uma falha no documento 3 deixa os artefatos dos documentos 1 e
     * 2 no disco, e a próxima execução os reaproveita (`steps` diz o que foi `created` e o que
     * foi `reused`). Cada documento usa um diretório temporário próprio.
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     *
     * @throws FinalizationException
     */
    private function handleMulti(Envelope $envelope, array $sent, string $correlationId): FinalizationOutcome
    {
        if ($envelope->verification_code === null) {
            throw FinalizationException::missingVerificationCode();
        }

        // Falha rápida e sem trabalho parcial: todos os bytes congelados precisam existir.
        foreach ($sent as $row) {
            if (! $this->storage->exists($row['version'])) {
                throw FinalizationException::sourceUnavailable();
            }
        }

        $this->ensureFinalizationKey($envelope);

        $steps = [];
        $consolidated = [];
        $rebuilt = [];
        $rebuiltAny = false;

        // -- a. Consolidação de cada documento -----------------------------------------
        foreach ($sent as $index => $row) {
            $key = self::documentStepKey($index);
            $existing = $this->artifacts->existing($row['document'], DocumentVersionKind::Consolidated);

            if ($existing === null) {
                $existing = $this->withWorkDir(fn (TemporaryDirectory $dir): DocumentVersion => $this->consolidate(
                    $envelope, $row['document'], $row['version'], $dir, $correlationId, self::documentAudit($row['document']),
                ));

                $steps[$key.'.consolidated'] = 'created';
                $rebuilt[$index] = true;
                $rebuiltAny = true;
            } else {
                $steps[$key.'.consolidated'] = 'reused';
                $rebuilt[$index] = false;
            }

            $consolidated[$index] = $existing;
        }

        // -- b. Página de evidências de cada documento ----------------------------------
        $configuredCertificate = $this->signsFor($envelope)
            ? $this->signature->configuredCertificate($correlationId)
            : null;

        $manifest = $this->documentsManifest($sent, $consolidated);
        $variant = $this->evidenceVariant($envelope, $configuredCertificate) + [
            'documents_digest' => self::documentsDigest($manifest),
        ];

        $evidences = [];

        foreach ($sent as $index => $row) {
            $key = self::documentStepKey($index);
            $evidence = $this->artifacts->existing($row['document'], DocumentVersionKind::Evidence);

            if ($evidence !== null && ($rebuiltAny || ! $this->evidenceMatchesVariant($envelope, $evidence, $variant))) {
                $this->artifacts->discard($evidence, $rebuiltAny
                    ? 'um documento consolidado do envelope foi refeito nesta execução'
                    : 'a página de evidências descreve outra configuração de assinatura ou outro conjunto de documentos');

                $evidence = null;
            }

            if ($evidence === null) {
                $evidence = $this->withWorkDir(fn (TemporaryDirectory $dir): DocumentVersion => $this->generateEvidence(
                    $envelope, $row['document'], $row['version'], $consolidated[$index], $configuredCertificate, $variant, $dir, $correlationId,
                    $manifest, (int) $row['document']->position,
                ));

                $steps[$key.'.evidence'] = 'created';
                $rebuilt[$index] = true;
            } else {
                $steps[$key.'.evidence'] = 'reused';
            }

            $evidences[$index] = $evidence;
        }

        // -- Fase 2 §2.12: assinaturas com o certificado dos participantes (por documento) --
        if ($this->participants->activeFor($envelope)) {
            return $this->participantsPipeline($envelope, $sent, $consolidated, $evidences, $rebuilt, $correlationId, $steps);
        }

        // -- c/d/e. Arquivo final de cada documento --------------------------------------
        $results = [];

        foreach ($sent as $index => $row) {
            $key = self::documentStepKey($index);

            $results[$index] = $this->withWorkDir(function (TemporaryDirectory $dir) use ($envelope, $row, $index, $key, $rebuilt, $consolidated, $evidences, $correlationId, &$steps): array {
                $final = $this->reusableFinal($envelope, $row['document'], $dir, $correlationId);

                if ($final !== null && $rebuilt[$index]) {
                    $this->artifacts->discard($final, 'o arquivo final embute artefatos refeitos nesta execução');

                    $final = null;
                }

                if ($final === null) {
                    [$final, $signatureStatus, $profile, $validation, $certificate] = $this->buildFinal(
                        $envelope, $row['document'], $consolidated[$index], $evidences[$index], $dir, $correlationId, self::documentAudit($row['document']),
                    );

                    $steps[$key.'.final'] = 'created';
                    $steps[$key.'.signature'] = $signatureStatus === SignatureStatus::CompanyA1 ? 'created' : 'skipped';

                    SignerAudit::system($envelope, AuditEventType::DocumentFinalized, self::documentAudit($row['document']) + [
                        'final_document_version_ulid' => $final->ulid,
                        'final_sha256' => $final->sha256,
                        'signature_status' => $signatureStatus->value,
                    ], null, $correlationId);
                } else {
                    [$signatureStatus, $profile, $validation, $certificate] = $this->recoverSignatureState($envelope, $final, $dir, $correlationId);
                    $steps[$key.'.final'] = 'reused';
                    $steps[$key.'.signature'] = 'reused';
                }

                return [
                    'final' => $final,
                    'status' => $signatureStatus,
                    'profile' => $profile,
                    'validation' => $validation,
                    'certificate' => $certificate,
                ];
            });
        }

        // -- f. Registro de verificação (um por envelope + um por documento) -------------
        $first = $results[0];

        $record = $this->verificationRecord(
            $envelope,
            $sent[0]['document'],
            $sent[0]['version'],
            $consolidated[0],
            $first['final'],
            $first['status'],
            $first['profile'],
            $first['validation'],
            $first['certificate'],
            $steps,
        );

        $steps['verification_documents'] = $this->verificationDocuments($record, $sent, $consolidated, $results);

        // -- g. Conclusão: só com TODOS os finais ----------------------------------------
        $documentFinals = [];
        $summary = [];

        foreach ($sent as $index => $row) {
            $documentFinals[(int) $row['document']->getKey()] = (int) $results[$index]['final']->getKey();
            $summary[] = [
                'document_ulid' => $row['document']->ulid,
                'final_sha256' => $results[$index]['final']->sha256,
            ];
        }

        $completed = $this->complete($envelope, $first['final'], $record, $first['status'], $correlationId, $steps, $documentFinals, [
            'documents' => $summary,
        ]);

        return new FinalizationOutcome(
            $completed ? 'completed' : 'already_completed',
            $envelope->refresh(),
            $first['final'],
            $record,
            $first['status'],
            $steps,
        );
    }

    /**
     * Grava (ou confirma) uma linha de `verification_record_documents` por documento.
     * Devolve `written` quando algo mudou e `reused` quando tudo já descrevia esta execução.
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @param  array<int, DocumentVersion>  $consolidated
     * @param  array<int, array{final: DocumentVersion}>  $results
     */
    private function verificationDocuments(VerificationRecord $record, array $sent, array $consolidated, array $results): string
    {
        $changed = false;

        foreach ($sent as $index => $row) {
            /** @var DocumentVersion $final */
            $final = $results[$index]['final'];

            /** @var VerificationRecordDocument $child */
            $child = VerificationRecordDocument::query()->firstOrNew([
                'verification_record_id' => $record->getKey(),
                'position' => (int) $row['document']->position,
            ]);

            $child->fill([
                'document_id' => $row['document']->getKey(),
                'name' => $row['document']->name,
                'original_sha256' => $this->originalSha256($row['document']),
                'sent_sha256' => $row['version']->sha256,
                'consolidated_sha256' => $consolidated[$index]->sha256,
                'final_sha256' => $final->sha256,
                'final_document_version_id' => $final->getKey(),
                'page_count' => (int) ($row['version']->page_count ?? 0),
            ]);

            if (! $child->exists || $child->isDirty()) {
                $child->save();
                $changed = true;
            }
        }

        return $changed ? 'written' : 'reused';
    }

    /**
     * O que a página de evidências de cada documento lista sobre TODOS os documentos.
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @param  array<int, DocumentVersion>  $consolidated
     * @return list<array{document: Document, position: int, name: string, page_count: int, hashes: array{original: string|null, sent: string, consolidated: string}}>
     */
    private function documentsManifest(array $sent, array $consolidated): array
    {
        $manifest = [];

        foreach ($sent as $index => $row) {
            $manifest[] = [
                'document' => $row['document'],
                'position' => (int) $row['document']->position,
                'name' => $row['document']->name,
                'page_count' => (int) ($row['version']->page_count ?? 0),
                'hashes' => [
                    'original' => $this->originalSha256($row['document']),
                    'sent' => $row['version']->sha256,
                    'consolidated' => $consolidated[$index]->sha256,
                ],
            ];
        }

        return $manifest;
    }

    /**
     * Marca do conjunto de documentos impresso nas páginas de evidências: se qualquer resumo
     * mudar, a marca muda e as páginas gravadas deixam de servir.
     *
     * @param  list<array{position: int, hashes: array{original: string|null, sent: string, consolidated: string}}>  $manifest
     */
    private static function documentsDigest(array $manifest): string
    {
        return hash('sha256', (string) json_encode(array_map(
            static fn (array $row): array => [$row['position'], $row['hashes']['original'], $row['hashes']['sent'], $row['hashes']['consolidated']],
            $manifest,
        ), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{document_ulid: string, position: int}
     */
    private static function documentAudit(Document $document): array
    {
        return ['document_ulid' => $document->ulid, 'position' => (int) $document->position];
    }

    private static function documentStepKey(int $index): string
    {
        return 'documents.'.($index + 1);
    }

    /**
     * Executa `$callback` num diretório temporário exclusivo, removido ao final — um por
     * documento, para que artefatos de um nunca sejam lidos por engano no outro.
     *
     * @template T
     *
     * @param  callable(TemporaryDirectory): T  $callback
     * @return T
     */
    private function withWorkDir(callable $callback): mixed
    {
        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'finalize-');

        try {
            return $callback($workDir);
        } finally {
            $workDir->delete();
        }
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

    /**
     * @param  array<string, mixed>  $auditExtra  identificação do documento (vários documentos)
     */
    private function consolidate(
        Envelope $envelope,
        Document $document,
        DocumentVersion $sentVersion,
        TemporaryDirectory $workDir,
        string $correlationId,
        array $auditExtra = [],
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
        ] + $auditExtra, null, $correlationId);

        return $version;
    }

    // -- Etapa b ---------------------------------------------------------------------

    /**
     * @param  array{signature_status: string, certificate_fingerprint_sha256: string|null, documents_digest?: string, participant_mode?: bool}  $variant
     * @param  list<array<string, mixed>>  $documents  todos os documentos do envelope (vários documentos)
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
        array $documents = [],
        ?int $position = null,
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
            documents: $documents,
            position: $position,
            participantMode: ($variant['participant_mode'] ?? false) === true,
        );

        $output = $this->evidenceRenderer->render($data, $workDir->path('evidencias.pdf'));

        $version = $this->artifacts->store($envelope, $document, DocumentVersionKind::Evidence, $output, $correlationId);

        $extra = [];

        if ($documents !== []) {
            $extra = self::documentAudit($document) + [
                'documents' => count($documents),
                'documents_digest' => $variant['documents_digest'] ?? null,
            ];
        }

        SignerAudit::system($envelope, AuditEventType::EnvelopeEvidenceGenerated, $extra + [
            'document_version_ulid' => $version->ulid,
            'sha256' => $version->sha256,
            'page_count' => $version->page_count,
            'participants' => count($data['participants']),
            'timeline_events' => count($data['timeline']),
            // Marca da variante impressa (ver evidenceMatchesVariant()). Impressão digital de
            // certificado é dado público — não é segredo, não é a chave e não é a senha.
            'signature_status' => $variant['signature_status'],
            'certificate_fingerprint_sha256' => $variant['certificate_fingerprint_sha256'],
        ] + (($variant['participant_mode'] ?? false) === true ? ['participant_mode' => true] : []), null, $correlationId);

        return $version;
    }

    /**
     * O que a página de evidências vai afirmar sobre a assinatura desta execução.
     *
     * `participant_mode` (Fase 2 §2.12) só existe quando o envelope tem pedidos de assinatura
     * com o certificado do participante: a página passa a dizer que essas assinaturas vêm
     * depois dela. Sem pedidos, a variante é exatamente a da Fase 1.
     *
     * @return array{signature_status: string, certificate_fingerprint_sha256: string|null, participant_mode?: bool}
     */
    private function evidenceVariant(Envelope $envelope, ?CertificateReference $certificate): array
    {
        $willSign = $this->signsFor($envelope);

        $variant = [
            'signature_status' => ($willSign ? SignatureStatus::CompanyA1 : SignatureStatus::None)->value,
            'certificate_fingerprint_sha256' => $willSign ? $certificate?->fingerprint_sha256 : null,
        ];

        if ($this->participants->activeFor($envelope)) {
            $variant['participant_mode'] = true;
        }

        return $variant;
    }

    /**
     * A página de evidências gravada descreve a mesma variante desta execução?
     *
     * A marca fica no evento `envelope.evidence_generated` da trilha — append-only, nunca
     * reescrita, e já o registro de quando cada artefato nasceu. Marca ausente (página de uma
     * versão anterior do pipeline) conta como divergência: regerar custa um render; publicar
     * uma afirmação de assinatura que não corresponde ao arquivo não tem conserto.
     *
     * Com vários documentos, a marca inclui também `documents_digest` (os resumos de todos os
     * documentos que a página lista).
     *
     * @param  array{signature_status: string, certificate_fingerprint_sha256: string|null, documents_digest?: string, participant_mode?: bool}  $variant
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
                && ($payload['certificate_fingerprint_sha256'] ?? null) === $variant['certificate_fingerprint_sha256']
                && (($payload['participant_mode'] ?? false) === true) === (($variant['participant_mode'] ?? false) === true)
                && (! array_key_exists('documents_digest', $variant)
                    || ($payload['documents_digest'] ?? null) === $variant['documents_digest']);
        }

        return false;
    }

    // -- Etapas c, d, e ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $auditExtra  identificação do documento (vários documentos)
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
        array $auditExtra = [],
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
            ] + $auditExtra, null, $correlationId);

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

    // -- Fase 2 §2.12: assinaturas com o certificado dos participantes -----------------

    /**
     * Etapas c/d/e/f/g quando o envelope tem pedidos de assinatura com o certificado A1 dos
     * próprios participantes (docs/fase-2/a1-do-participante.md §3). Ordem, por documento:
     *
     * ```
     *  consolidado ─┐
     *  evidências ──┴─ append ──► pre_signature (base congelada, sem assinatura)
     *                                 │ participante 1 (ApplyParticipantSignature, sob o lock)
     *                              signed_incremental #1
     *                                 │ participante 2 …
     *                              signed_incremental #N
     *                                 │ operadora POR ÚLTIMO (se configurada e no plano)
     *                              final ── sha256 final, calculado DEPOIS da última assinatura
     * ```
     *
     * Enquanto houver pedido pendente dentro do prazo, a finalização PARA aqui com
     * `awaiting_participant_signatures`: o envelope continua em `finalizing` e nada é
     * publicado. A base, a decisão "não falta ninguém" e a montagem do `final` acontecem sob
     * o lock do envelope — o mesmo das assinaturas dos participantes —, então nenhuma
     * assinatura de participante entra entre a decisão e o `final`.
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @param  array<int, DocumentVersion>  $consolidated
     * @param  array<int, DocumentVersion>  $evidences
     * @param  array<int, bool>  $rebuilt
     * @param  array<string, string>  $steps
     *
     * @throws FinalizationException
     */
    private function participantsPipeline(
        Envelope $envelope,
        array $sent,
        array $consolidated,
        array $evidences,
        array $rebuilt,
        string $correlationId,
        array $steps,
    ): FinalizationOutcome {
        $multi = count($sent) > 1;
        $key = static fn (int $index, string $step): string => $multi ? self::documentStepKey($index).'.'.$step : $step;

        /** @var array<int, array{final: DocumentVersion, status: SignatureStatus, profile: string|null, validation: array<string, mixed>, certificate: CertificateReference|null}>|null $results */
        $results = $this->participants->locked($envelope, function () use ($envelope, $sent, $consolidated, $evidences, $rebuilt, $correlationId, $multi, $key, &$steps): ?array {
            foreach ($sent as $index => $row) {
                $steps[$key($index, 'pre_signature')] = $this->withWorkDir(fn (TemporaryDirectory $dir): string => $this->participants->ensureBase(
                    $envelope, $row['document'], $consolidated[$index], $evidences[$index], $dir, $correlationId, (bool) ($rebuilt[$index] ?? false),
                ));
            }

            if ($this->participants->awaiting($envelope, $correlationId)) {
                return null;
            }

            $results = [];

            foreach ($sent as $index => $row) {
                $results[$index] = $this->withWorkDir(function (TemporaryDirectory $dir) use ($envelope, $row, $index, $correlationId, $multi, $key, &$steps): array {
                    return $this->participantFinal(
                        $envelope,
                        $row['document'],
                        $dir,
                        $correlationId,
                        $multi ? self::documentAudit($row['document']) : [],
                        $steps,
                        $key($index, 'final'),
                        $key($index, 'signature'),
                    );
                });
            }

            return $results;
        });

        if ($results === null) {
            $steps['participants'] = 'awaiting';

            return new FinalizationOutcome('awaiting_participant_signatures', $envelope->refresh(), null, null, SignatureStatus::None, $steps);
        }

        $first = $results[0];

        $record = $this->verificationRecord(
            $envelope,
            $sent[0]['document'],
            $sent[0]['version'],
            $consolidated[0],
            $first['final'],
            $first['status'],
            $first['profile'],
            $first['validation'],
            $first['certificate'],
            $steps,
        );

        $steps['verification_documents'] = $this->verificationDocuments($record, $sent, $consolidated, $results);

        $documentFinals = [];
        $summary = [];

        foreach ($sent as $index => $row) {
            $documentFinals[(int) $row['document']->getKey()] = (int) $results[$index]['final']->getKey();
            $summary[] = [
                'document_ulid' => $row['document']->ulid,
                'final_sha256' => $results[$index]['final']->sha256,
            ];
        }

        $completed = $this->complete($envelope, $first['final'], $record, $first['status'], $correlationId, $steps, $documentFinals, [
            'participant_signatures' => $this->participants->appliedRequestCount($envelope),
        ] + ($multi ? ['documents' => $summary] : []));

        return new FinalizationOutcome(
            $completed ? 'completed' : 'already_completed',
            $envelope->refresh(),
            $first['final'],
            $record,
            $first['status'],
            $steps,
        );
    }

    /**
     * Arquivo final de UM documento sobre a última revisão assinada pelos participantes:
     * a operadora assina por último (se configurada) e TODAS as assinaturas são validadas
     * juntas — cadeia íntegra, nenhuma a mais, nenhuma a menos. Chamado sob o lock.
     *
     * @param  array<string, mixed>  $auditExtra
     * @param  array<string, string>  $steps
     * @return array{final: DocumentVersion, status: SignatureStatus, profile: string|null, validation: array<string, mixed>, certificate: CertificateReference|null}
     *
     * @throws FinalizationException
     */
    private function participantFinal(
        Envelope $envelope,
        Document $document,
        TemporaryDirectory $workDir,
        string $correlationId,
        array $auditExtra,
        array &$steps,
        string $finalKey,
        string $signatureKey,
    ): array {
        $operator = $this->signsFor($envelope);
        $latest = $this->participants->latestRevision($document);
        $participantSignatures = $this->participants->signatureCount($document);
        $expected = $participantSignatures + ($operator ? 1 : 0);
        // Fase 3 §3.4 (P3-EXT): o meio de cada assinatura entra no status (idêntico ao de antes sem assinatura externa).
        $status = $this->participants->statusForDocument($document, $operator, $participantSignatures);
        $profile = $expected > 0 ? PyHankoSigner::PROFILE : null;

        // Reaproveitar um `final` de execução anterior exige que ele seja a última revisão
        // assinada + (só) a assinatura da operadora, com a quantidade exata de assinaturas.
        $final = $this->artifacts->existing($document, DocumentVersionKind::Final);

        if ($final !== null) {
            $local = $this->artifacts->copyToTemporary($final, $workDir, 'final-existente.pdf');

            if ($this->participants->finalMatches($local, $latest, $expected, $correlationId)) {
                $validation = $expected > 0 ? $this->participants->validate($local, $correlationId) : null;

                if ($validation !== null) {
                    IncrementalChain::assertSound($validation, $expected);
                }

                $certificate = $operator && $validation !== null ? $this->operatorCertificateFor($validation) : null;
                $steps[$finalKey] = 'reused';
                $steps[$signatureKey] = 'reused';

                return [
                    'final' => $final,
                    'status' => $status,
                    'profile' => $profile,
                    'validation' => $this->participants->validationPayload(
                        $status,
                        $validation,
                        $profile,
                        $operator ? ($certificate->environment ?? CertificateEnvironment::Test) : null,
                    ),
                    'certificate' => $certificate,
                ];
            }

            $this->artifacts->discard($final, 'o arquivo final não corresponde à última revisão assinada pelos participantes');
        }

        $base = $this->artifacts->copyToTemporary($latest, $workDir, 'pre-assinatura.pdf');
        $finalPath = $workDir->path('final.pdf');
        $certificate = null;
        $signResult = null;

        if ($operator) {
            $signed = $this->signature->signAndValidate($base, $finalPath, $correlationId, static function (ValidationResult $validation) use ($expected): void {
                IncrementalChain::assertSound($validation, $expected);
            });

            /** @var CertificateReference|null $certificate */
            $certificate = $signed['certificate'];
            $profile = $signed['profile'] ?? PyHankoSigner::PROFILE;
            $signResult = $signed['result'];
        } elseif (! @copy($base, $finalPath)) {
            throw FinalizationException::writeFailed('final', ['reason' => 'copy_failed']);
        }

        $validation = null;

        if ($expected > 0) {
            // TODAS as assinaturas do arquivo pronto, com as raízes dos participantes e da operadora.
            $validation = $this->participants->validate($finalPath, $correlationId);
            IncrementalChain::assertSound($validation, $expected);
        }

        $version = $this->artifacts->store($envelope, $document, DocumentVersionKind::Final, $finalPath, $correlationId);
        $environment = $operator ? ($certificate->environment ?? CertificateEnvironment::Test) : null;

        if ($signResult !== null && $validation !== null) {
            SignerAudit::system($envelope, AuditEventType::EnvelopeSignedCompanyA1, [
                'document_version_ulid' => $version->ulid,
                'profile' => $profile,
                'field_name' => $signResult->fieldName,
                'certificate_fingerprint_sha256' => $signResult->certFingerprintSha256,
                'certificate_environment' => $environment?->value,
                'timestamp' => null,
                'signature_count' => $validation->signatureCount,
                'participant_signatures' => $participantSignatures,
                'all_intact' => $validation->allIntact,
                'all_valid' => $validation->allValid,
                'all_trusted' => $validation->allTrusted(),
                'revocation' => $validation->revocation,
            ] + $auditExtra, null, $correlationId);
        }

        if ($auditExtra !== []) {
            SignerAudit::system($envelope, AuditEventType::DocumentFinalized, $auditExtra + [
                'final_document_version_ulid' => $version->ulid,
                'final_sha256' => $version->sha256,
                'signature_status' => $status->value,
            ], null, $correlationId);
        }

        $steps[$finalKey] = 'created';
        $steps[$signatureKey] = $operator ? 'created' : 'skipped';

        return [
            'final' => $version,
            'status' => $status,
            'profile' => $profile,
            'validation' => $this->participants->validationPayload($status, $validation, $profile, $environment),
            'certificate' => $certificate,
        ];
    }

    /**
     * Certificado da operadora identificado pela ÚLTIMA assinatura do arquivo (a dela).
     */
    private function operatorCertificateFor(ValidationResult $validation): ?CertificateReference
    {
        $signatures = $validation->signatures;
        $fingerprint = $signatures === [] ? null : $signatures[count($signatures) - 1]->certFingerprintSha256;

        if ($fingerprint === null || $fingerprint === '') {
            return null;
        }

        /** @var CertificateReference|null $certificate */
        $certificate = CertificateReference::query()->where('fingerprint_sha256', $fingerprint)->first();

        return $certificate;
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
            // Perfil só quando há assinatura criptográfica (operadora, participantes ou ambas).
            'signature_profile' => $signatureStatus !== SignatureStatus::None ? $profile : null,
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
     * `$documentFinals` (documento → versão final) é gravado em `documents.final_version_id`
     * na MESMA transação da conclusão: não existe envelope concluído com documento sem final.
     *
     * @param  array<string, string>  $steps
     * @param  array<int, int>  $documentFinals
     * @param  array<string, mixed>  $extraPayload
     */
    private function complete(
        Envelope $envelope,
        DocumentVersion $final,
        VerificationRecord $record,
        SignatureStatus $signatureStatus,
        string $correlationId,
        array &$steps,
        array $documentFinals = [],
        array $extraPayload = [],
    ): bool {
        $completed = DB::transaction(function () use ($envelope, $final, $record, $signatureStatus, $correlationId, $documentFinals, $extraPayload): bool {
            /** @var Envelope|null $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== EnvelopeStatus::Finalizing) {
                return false;
            }

            foreach ($documentFinals as $documentId => $finalId) {
                Document::withoutOrganizationScope()
                    ->whereKey($documentId)
                    ->where('envelope_id', $locked->getKey())
                    ->update(['final_version_id' => $finalId]);
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
            ] + $extraPayload, null, $correlationId);

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
