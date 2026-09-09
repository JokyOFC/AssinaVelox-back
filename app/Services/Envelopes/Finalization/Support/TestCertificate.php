<?php

namespace App\Services\Envelopes\Finalization\Support;

use App\Enums\CertificateEnvironment;
use App\Enums\CertificateKind;
use App\Integrations\Contracts\PdfSigner;
use App\Models\CertificateReference;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Certificado A1 **de teste** para exercitar a assinatura da operadora em desenvolvimento,
 * CI e testes automatizados.
 *
 * O certificado é autoassinado, tem `TESTE` no CN e é rotulado `environment = test` em toda
 * a cadeia (config, `certificate_references`, `SignResult`, página de evidências e página
 * pública). **Ele não é ICP-Brasil, não tem validade jurídica e nunca pode ser apresentado
 * como se tivesse.**
 *
 * A senha continua obedecendo a regra do projeto: quem chama define o **nome** de uma
 * variável de ambiente e coloca o valor lá (`putenv`, ambiente do processo, systemd). Nem a
 * configuração, nem o argv, nem o log, nem a fila veem o valor.
 *
 * Uso típico (tinker, seeder de desenvolvimento ou `beforeEach` de teste):
 *
 * ```php
 * putenv('ASSINAVELOX_TEST_CERT_PASS=uma-senha-forte-local');
 * $certificate = TestCertificate::generate(storage_path('app/private/certs'), 'ASSINAVELOX_TEST_CERT_PASS');
 * TestCertificate::configure($certificate);   // liga o PyHankoSigner no container
 * ```
 *
 * O marco "assinatura A1 com credencial de PRODUÇÃO" **permanece pendente**: ele só pode ser
 * dado como cumprido depois de assinar com um A1 real emitido por AC da ICP-Brasil, com a
 * cadeia da AC em `PDFTOOL_TRUST_ROOTS` e a senha no ambiente real do serviço. Nada nesta
 * classe substitui esse teste.
 */
final class TestCertificate
{
    public const SUBJECT = 'CN=AssinaVelox TESTE,O=AssinaVelox,C=BR';

    /**
     * Gera `.pfx` (PKCS#12) e `.pem` (para uso como raiz de confiança em `validate`).
     *
     * @return array{pfx: string, pem: string, password_env: string, raw: array<string, mixed>}
     */
    public static function generate(
        string $directory,
        string $passwordEnvName,
        string $subject = self::SUBJECT,
        int $days = 30,
        ?PdfToolClient $client = null,
    ): array {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $passwordEnvName) !== 1) {
            throw new InvalidArgumentException('Nome de variável de ambiente inválido para a senha do certificado.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException("Não foi possível criar o diretório do certificado: {$directory}");
        }

        $client ??= app(PdfToolClient::class);

        $pfx = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'assinavelox-teste.pfx';
        $pem = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'assinavelox-teste.pem';

        $raw = $client->generateTestCertificate($pfx, $passwordEnvName, $subject, $days, $pem);

        return ['pfx' => $pfx, 'pem' => $pem, 'password_env' => $passwordEnvName, 'raw' => $raw];
    }

    /**
     * Aponta a configuração de assinatura para o certificado gerado e devolve o signer
     * resolvido (um `PyHankoSigner` configurado). O `.pem` entra como raiz de confiança para
     * que `validate` possa afirmar `trusted`.
     *
     * @param  array{pfx: string, pem: string, password_env: string, raw?: array<string, mixed>}  $certificate
     */
    public static function configure(array $certificate, bool $trustRoot = true): PdfSigner
    {
        config()->set('pdftool.company_certificate', [
            'enabled' => true,
            'pfx_path' => $certificate['pfx'],
            'password_env' => $certificate['password_env'],
            'environment' => CertificateEnvironment::Test->value,
            'name' => 'Certificado de teste da operadora',
            'reason' => 'Assinatura eletrônica AssinaVelox (teste)',
            'location' => 'Brasil',
            'field_name' => 'AssinaVelox',
        ]);

        config()->set('pdftool.trust_roots', $trustRoot ? [$certificate['pem']] : []);

        // O binding de PdfSigner é resolvido a cada make() e o PyHankoSigner lê a config no
        // momento da chamada: mudar a configuração basta, não é preciso trocar instância.
        return app()->make(PdfSigner::class);
    }

    /**
     * Registro administrativo do certificado de teste em `certificate_references`
     * (`organization_id` nulo = certificado da operadora). Guarda metadados e o NOME da
     * variável de senha — nunca o PFX, nunca a senha.
     *
     * @param  array{pfx: string, pem: string, password_env: string, raw?: array<string, mixed>}  $certificate
     */
    public static function register(array $certificate): CertificateReference
    {
        $raw = $certificate['raw'] ?? [];
        $fingerprint = (string) ($raw['cert_fingerprint_sha256'] ?? '');

        /** @var CertificateReference $reference */
        $reference = CertificateReference::query()->firstOrNew(
            $fingerprint !== '' ? ['fingerprint_sha256' => $fingerprint] : ['secret_ref' => $certificate['password_env']],
        );

        $reference->forceFill([
            'organization_id' => null,
            'name' => 'Certificado de teste da operadora',
            'kind' => CertificateKind::CompanyA1->value,
            'environment' => CertificateEnvironment::Test->value,
            'secret_ref' => $certificate['password_env'],
            'subject' => $raw['subject'] ?? null,
            'issuer' => $raw['issuer'] ?? null,
            'serial_number' => $raw['serial_hex'] ?? null,
            'fingerprint_sha256' => $fingerprint !== '' ? $fingerprint : null,
            'not_before' => isset($raw['not_before']) ? Carbon::parse((string) $raw['not_before']) : null,
            'not_after' => isset($raw['not_after']) ? Carbon::parse((string) $raw['not_after']) : null,
            'is_active' => true,
        ])->save();

        return $reference;
    }
}
