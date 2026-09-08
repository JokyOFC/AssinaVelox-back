<?php

namespace App\Integrations\Pdf;

use App\Enums\CertificateEnvironment;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Dto\SignRequest;
use App\Integrations\Exceptions\SignerNotConfiguredException;
use App\Services\Pdf\Dto\SignOptions;
use App\Services\Pdf\Dto\SignResult;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Assinatura PAdES B-B com o certificado A1 da empresa operadora via
 * `pdftool sign` (pyHanko).
 *
 * Configuração (config/pdftool.php, company_certificate): enabled
 * (COMPANY_CERT_ENABLED), pfx_path (COMPANY_CERT_PFX_PATH) e o NOME da variável
 * de ambiente com a senha do PKCS#12 (COMPANY_CERT_PASSWORD_ENV, padrão
 * COMPANY_CERT_PASSWORD). O valor da senha fica apenas no ambiente do PHP e é
 * injetado pelo PdfToolClient no ambiente do processo filho sob esse nome —
 * nunca em argv, log, fila ou banco. isConfigured() exige enabled=true, arquivo
 * PFX existente, variável de senha definida e pdftool disponível.
 *
 * O que NÃO é afirmado: carimbo do tempo (B-T), LTV/LTA, verificação de
 * revogação. `environment` (test|production) rotula o certificado; certificados
 * de teste nunca são exibidos como ICP-Brasil.
 */
class PyHankoSigner implements PdfSigner
{
    public const NAME = 'pyhanko';

    public const PROFILE = 'PAdES-B-B';

    public function __construct(
        private readonly PdfToolClient $client,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEnabled(): bool
    {
        return filter_var($this->certificate('enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function pfxPath(): string
    {
        return trim((string) $this->certificate('pfx_path', ''));
    }

    /**
     * NOME da variável de ambiente que contém a senha do PKCS#12 (nunca o valor).
     */
    public function passwordEnvName(): string
    {
        return trim((string) $this->certificate('password_env', 'COMPANY_CERT_PASSWORD'));
    }

    public function certificateName(): string
    {
        return (string) $this->certificate('name', 'Certificado da operadora');
    }

    /**
     * Ambiente do certificado. Valor inválido => Test (nunca assume produção).
     */
    public function environment(): CertificateEnvironment
    {
        $raw = strtolower(trim((string) $this->certificate('environment', 'test')));
        $environment = CertificateEnvironment::tryFrom($raw);

        if ($environment === null) {
            $this->logger->warning('PyHankoSigner: COMPANY_CERT_ENVIRONMENT inválido; assumindo "test".', ['value' => $raw]);

            return CertificateEnvironment::Test;
        }

        return $environment;
    }

    /**
     * @return list<string>
     */
    public function trustRoots(): array
    {
        $roots = [];
        foreach ((array) $this->config->get('pdftool.trust_roots', []) as $root) {
            if (is_string($root) && trim($root) !== '') {
                $roots[] = trim($root);
            }
        }

        return $roots;
    }

    public function isConfigured(): bool
    {
        return $this->configurationProblems() === [];
    }

    /**
     * Motivos (legíveis, sem segredos) pelos quais o signer não está pronto.
     *
     * @return list<string>
     */
    public function configurationProblems(): array
    {
        $problems = [];

        if (! $this->isEnabled()) {
            $problems[] = 'COMPANY_CERT_ENABLED não é true (assinatura criptográfica desligada).';
        }

        $pfx = $this->pfxPath();
        if ($pfx === '') {
            $problems[] = 'COMPANY_CERT_PFX_PATH não definido.';
        } elseif (! is_file($pfx)) {
            $problems[] = 'Arquivo PKCS#12 não encontrado em COMPANY_CERT_PFX_PATH.';
        }

        $envName = $this->passwordEnvName();
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $envName) !== 1) {
            $problems[] = 'COMPANY_CERT_PASSWORD_ENV não é um nome de variável válido.';
        } elseif (! $this->passwordIsPresent($envName)) {
            $problems[] = sprintf('Variável de ambiente %s (senha do PKCS#12) não definida ou vazia no ambiente do PHP.', $envName);
        }

        if (! $this->client->isAvailable()) {
            $problems[] = 'pdftool indisponível (venv em tools/pdftool/.venv).';
        }

        return $problems;
    }

    public function sign(SignRequest $request): SignResult
    {
        $problems = $this->configurationProblems();
        if ($problems !== []) {
            throw SignerNotConfiguredException::make(implode(' ', $problems));
        }

        $correlationId = $request->correlationId ?? (string) Str::ulid();

        $options = new SignOptions(
            fieldName: $request->fieldName ?? (string) $this->certificate('field_name', 'AssinaVelox'),
            reason: $request->reason ?? $this->nullable($this->certificate('reason')),
            location: $request->location ?? $this->nullable($this->certificate('location')),
            contact: $request->contact,
            visible: $request->visible,
        );

        $result = $this->client->sign(
            $request->inputPath,
            $request->outputPath,
            $this->pfxPath(),
            $this->passwordEnvName(),
            $options,
            $correlationId,
        );

        if ($result->profile !== self::PROFILE) {
            $this->logger->warning('PyHankoSigner: perfil inesperado devolvido pelo pdftool', [
                'profile' => $result->profile,
                'correlation_id' => $correlationId,
            ]);
        }

        $this->logger->info('PyHankoSigner: PDF assinado', [
            'profile' => $result->profile,
            'field_name' => $result->fieldName,
            'signer_subject' => $result->signerSubject,
            'cert_fingerprint_sha256' => $result->certFingerprintSha256,
            'environment' => $this->environment()->value,
            'correlation_id' => $correlationId,
        ]);

        return SignResult::fromArray($result->raw, $request->outputPath, $this->environment(), $correlationId);
    }

    public function validate(string $pdfPath, array $trustRoots = []): ValidationResult
    {
        return $this->client->validate($pdfPath, $trustRoots === [] ? $this->trustRoots() : $trustRoots);
    }

    private function passwordIsPresent(string $envName): bool
    {
        $value = Env::getRepository()->get($envName);

        return is_string($value) && $value !== '';
    }

    private function certificate(string $key, mixed $default = null): mixed
    {
        return $this->config->get('pdftool.company_certificate.'.$key, $default);
    }

    private function nullable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
