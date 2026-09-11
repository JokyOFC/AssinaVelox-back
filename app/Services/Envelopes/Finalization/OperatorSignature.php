<?php

namespace App\Services\Envelopes\Finalization;

use App\Enums\CertificateEnvironment;
use App\Enums\CertificateKind;
use App\Enums\SignatureStatus;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Dto\SignRequest;
use App\Integrations\Pdf\PyHankoSigner;
use App\Models\CertificateReference;
use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Pdf\Dto\SignResult;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Etapa (d): **assinatura criptográfica da empresa operadora** (PAdES B-B) e a validação
 * técnica do resultado.
 *
 * ## O que esta assinatura é e o que não é
 *
 * É uma assinatura aplicada com o certificado A1 **da AssinaVelox**. Ela identifica a
 * operadora que lacrou o arquivo e permite detectar alterações posteriores. Ela **não** é a
 * assinatura pessoal de nenhum participante nem um certificado emitido em nome deles — a
 * manifestação de vontade de cada um é o aceite eletrônico com evidências (arquitetura §2).
 *
 * O perfil produzido é **B-B**, e só isso é afirmado: sem carimbo do tempo (B-T), sem LTV,
 * sem LTA, sem DSS, sem consulta de revogação (o pdftool roda offline). `SignResult::timestamp`
 * é sempre nulo.
 *
 * ## Sem certificado
 *
 * `PdfSigner::isConfigured() === false` ⇒ a etapa é **pulada**. O arquivo pré-assinatura
 * vira o arquivo final e o envelope conclui com `signature_status = none`. Nada é simulado,
 * nenhum campo de assinatura é criado, nenhum texto diz "assinado digitalmente".
 *
 * ## Falha
 *
 * Se o signer está configurado e a assinatura falha, o envelope **não** conclui: a exceção
 * sobe, o job falha e o envelope fica em `finalizing` para nova tentativa. Concluir sem a
 * assinatura prometida é exatamente o que a semântica proíbe.
 *
 * ## Senha
 *
 * A senha do PKCS#12 nunca passa por aqui: o `PdfToolClient` a lê do ambiente do PHP pelo
 * NOME configurado e a injeta no ambiente do processo filho. Esta classe só conhece o nome
 * da variável, que é o que vai para `certificate_references.secret_ref`.
 */
class OperatorSignature
{
    public function __construct(
        private readonly PdfSigner $signer,
        private readonly LoggerInterface $logger,
        private readonly PdfToolClient $client,
    ) {}

    public function isConfigured(): bool
    {
        return $this->signer->isConfigured();
    }

    /**
     * O certificado que **esta execução** vai usar para assinar, identificado a partir do
     * PKCS#12 apontado pela configuração.
     *
     * Antes isto era um palpite: a página de evidências imprimia a linha mais recente e ativa
     * de `certificate_references`. Como essas linhas só nascem DEPOIS de uma assinatura
     * bem-sucedida, na primeira finalização com um certificado novo (rotação anual, troca de
     * teste para produção ou o contrário) o arquivo saía identificando o certificado
     * ANTERIOR — titular, emissor, série e validade de um certificado que não tocou naquele
     * arquivo — e, pior, o aviso obrigatório "certificado de ambiente de teste" seguia o
     * ambiente do certificado palpitado, não o do que assinou.
     *
     * Aqui não há palpite: `pdftool cert-info` abre o PKCS#12 configurado (senha lida do
     * ambiente pelo NOME, como em `sign`) e devolve os metadados públicos do certificado. A
     * identidade é a **impressão digital**: havendo linha com essa impressão, ela é
     * reaproveitada; não havendo, o modelo é montado em memória **sem ser persistido** — a
     * linha administrativa continua nascendo só depois de uma assinatura real.
     *
     * Sem conseguir identificar (adaptador sem PKCS#12, pdftool indisponível, senha errada) o
     * retorno é `null` e a página não imprime certificado nenhum. Imprimir outro seria pior
     * do que não imprimir.
     *
     * @return CertificateReference|null modelo possivelmente NÃO persistido, só para exibição
     */
    public function configuredCertificate(?string $correlationId = null): ?CertificateReference
    {
        if (! $this->signer instanceof PyHankoSigner || ! $this->signer->isConfigured()) {
            return null;
        }

        try {
            $info = $this->client->certificateInfo(
                $this->signer->pfxPath(),
                $this->signer->passwordEnvName(),
                $correlationId,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Finalização: não foi possível identificar o certificado configurado.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            return null;
        }

        $fingerprint = is_string($info['cert_fingerprint_sha256'] ?? null) ? $info['cert_fingerprint_sha256'] : '';

        if ($fingerprint === '') {
            return null;
        }

        /** @var CertificateReference $reference */
        $reference = CertificateReference::query()->where('fingerprint_sha256', $fingerprint)->first()
            ?? new CertificateReference;

        $string = static fn (string $key): ?string => is_string($info[$key] ?? null) && $info[$key] !== ''
            ? (string) $info[$key]
            : null;

        $reference->forceFill([
            'organization_id' => null,
            'name' => $this->signer->certificateName(),
            'kind' => CertificateKind::CompanyA1->value,
            // O ambiente é o da CONFIGURAÇÃO vigente, que é o que governa esta assinatura.
            'environment' => $this->signer->environment()->value,
            'secret_ref' => $this->signer->passwordEnvName(),
            'subject' => $string('subject'),
            'issuer' => $string('issuer'),
            'serial_number' => $string('serial_hex'),
            'fingerprint_sha256' => $fingerprint,
            'not_before' => $string('not_before') !== null ? Carbon::parse((string) $string('not_before')) : null,
            'not_after' => $string('not_after') !== null ? Carbon::parse((string) $string('not_after')) : null,
            'is_active' => true,
        ]);

        return $reference;
    }

    /**
     * Assina `$input` em `$output` e valida o resultado.
     *
     * `$assertion` (Fase 2 §2.12) substitui {@see self::assertPublishable()} quando o arquivo
     * já traz assinaturas de participantes: numa cadeia de revisões, as assinaturas anteriores
     * cobrem a própria revisão e `all_covering` é falso por construção — vale a análise da
     * cadeia (`IncrementalChain`). Sem `$assertion`, o comportamento é o da Fase 1.
     *
     * @param  (\Closure(ValidationResult): void)|null  $assertion
     * @return array{status: SignatureStatus, profile: string|null, result: SignResult, validation: ValidationResult, certificate: CertificateReference|null}
     *
     * @throws FinalizationException
     */
    public function signAndValidate(string $input, string $output, string $correlationId, ?\Closure $assertion = null): array
    {
        try {
            $result = $this->signer->sign(new SignRequest(
                inputPath: $input,
                outputPath: $output,
                correlationId: $correlationId,
            ));
        } catch (Throwable $exception) {
            $this->logger->error('Finalização: falha ao aplicar a assinatura da operadora.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            throw FinalizationException::signingFailed($exception);
        }

        $validation = $this->signer->validate($output);

        // A validação é obrigatória: um arquivo "assinado" que o próprio validador não
        // consegue verificar não pode ser publicado como assinado.
        if ($assertion !== null) {
            $assertion($validation);
        } else {
            self::assertPublishable($validation);
        }

        return [
            'status' => SignatureStatus::CompanyA1,
            'profile' => $result->profile,
            'result' => $result,
            'validation' => $validation,
            'certificate' => $this->certificateReference($result),
        ];
    }

    /**
     * Valida um arquivo final já existente (recuperação de uma execução interrompida).
     */
    public function validate(string $path): ValidationResult
    {
        return $this->signer->validate($path);
    }

    /**
     * Este resultado autoriza publicar o arquivo como **assinado**?
     *
     * Quatro condições, e as quatro são necessárias:
     *
     * - existe pelo menos uma assinatura;
     * - os bytes cobertos por ela não foram alterados (`allIntact`);
     * - a verificação criptográfica passou (`allValid`);
     * - a assinatura cobre o **arquivo inteiro** (`allCovering`) e nenhuma política DocMDP
     *   foi violada (`allDocmdpOk`).
     *
     * As duas últimas não são preciosismo. `intact` só fala dos bytes COBERTOS: um PDF com
     * uma atualização incremental acrescentada depois da revisão assinada continua
     * `intact = true, valid = true, trusted = true`, e a única pista é
     * `coverage = ENTIRE_REVISION`. Publicar esse arquivo como "assinado e íntegro" seria
     * afirmar sobre bytes que ninguém verificou.
     *
     * @throws FinalizationException
     */
    public static function assertPublishable(ValidationResult $validation): void
    {
        if ($validation->signatureCount >= 1
            && $validation->allIntact
            && $validation->allValid
            && $validation->allCovering
            && $validation->allDocmdpOk) {
            return;
        }

        throw FinalizationException::signatureNotVerifiable(sprintf(
            'signature_count=%d intact=%s valid=%s covering=%s docmdp_ok=%s',
            $validation->signatureCount,
            $validation->allIntact ? 'true' : 'false',
            $validation->allValid ? 'true' : 'false',
            $validation->allCovering ? 'true' : 'false',
            $validation->allDocmdpOk ? 'true' : 'false',
        ));
    }

    /**
     * `verification_records.validation_result` — o resultado técnico, honesto sobre o que
     * NÃO foi verificado. Este array é o contrato lido pela página pública de verificação.
     *
     * @return array<string, mixed>
     */
    public function validationPayload(
        SignatureStatus $status,
        ?ValidationResult $validation,
        ?string $profile,
        ?CertificateEnvironment $environment,
        ?string $reason = null,
    ): array {
        return [
            'signed' => $status === SignatureStatus::CompanyA1,
            'profile' => $profile,
            'environment' => $environment?->value,
            'validated_at' => Carbon::now()->utc()->toIso8601String(),
            // Fatos negativos que a plataforma não pode deixar implícitos.
            'timestamp' => null,
            'long_term_validation' => false,
            'revocation' => $validation->revocation ?? 'not_checked',
            'reason' => $reason,
            'result' => $validation?->summary(),
        ];
    }

    /**
     * Registro administrativo do certificado usado. Guarda metadados e o **nome** da
     * variável de ambiente com a senha (`secret_ref`) — nunca o PFX e nunca a senha.
     *
     * Identidade: `fingerprint_sha256`. Reassinar com o mesmo certificado reaproveita a
     * linha em vez de multiplicá-la.
     */
    private function certificateReference(SignResult $result): ?CertificateReference
    {
        $fingerprint = $result->certFingerprintSha256;

        if ($fingerprint === '') {
            return null;
        }

        $environment = $result->environment ?? CertificateEnvironment::Test;
        $secretRef = $this->signer instanceof PyHankoSigner
            ? $this->signer->passwordEnvName()
            : (string) config('pdftool.company_certificate.password_env', 'COMPANY_CERT_PASSWORD');

        /** @var CertificateReference $reference */
        $reference = CertificateReference::query()->firstOrNew([
            'fingerprint_sha256' => $fingerprint,
        ]);

        $reference->forceFill([
            'organization_id' => null,
            'name' => $this->signer instanceof PyHankoSigner
                ? $this->signer->certificateName()
                : (string) config('pdftool.company_certificate.name', 'Certificado da operadora'),
            'kind' => CertificateKind::CompanyA1->value,
            'environment' => $environment->value,
            'secret_ref' => $secretRef,
            'subject' => $result->signerSubject !== '' ? $result->signerSubject : null,
            'issuer' => $result->issuer !== '' ? $result->issuer : null,
            'serial_number' => $result->serialHex !== '' ? $result->serialHex : null,
            'fingerprint_sha256' => $fingerprint,
            'not_before' => $result->notBefore !== null ? Carbon::parse($result->notBefore) : null,
            'not_after' => $result->notAfter !== null ? Carbon::parse($result->notAfter) : null,
            'is_active' => true,
        ])->save();

        return $reference;
    }
}
