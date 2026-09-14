<?php

namespace App\Services\Signing\External;

use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * Âncoras de confiança para a cadeia do certificado do participante (Fase 3 §3.4),
 * configuradas por CAMINHO e FIXADAS por impressão digital SHA-256
 * (`assinavelox.external_signing.trust_anchors`: `caminho|sha256;...`).
 *
 * Uma âncora só é usada se o arquivo existir, contiver um certificado X.509 e a impressão
 * digital do certificado for exatamente a fixada. Arquivo trocado, ausente ou ilegível é
 * IGNORADO e registrado (sem conteúdo). Sem nenhuma âncora válida, a validação da cadeia é
 * "não verificada" — e nada aqui chama cadeia alguma de ICP-Brasil: a revogação (LCR/OCSP)
 * não é consultada nesta versão, requisito para `participant_icp_brasil` (roadmap §3.4).
 */
final class ExternalTrustAnchors
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{paths: list<string>, configured: int, pinned: int, rejected: list<array{index: int, reason: string}>}
     */
    public function resolve(): array
    {
        $paths = [];
        $rejected = [];
        $anchors = (array) $this->config->get('assinavelox.external_signing.trust_anchors', []);

        foreach (array_values($anchors) as $index => $anchor) {
            $path = is_array($anchor) ? (string) ($anchor['path'] ?? '') : '';
            $pin = is_array($anchor) ? strtolower(str_replace(':', '', (string) ($anchor['sha256'] ?? ''))) : '';
            $reason = $this->check($path, $pin);

            if ($reason === null) {
                $paths[] = $path;

                continue;
            }

            $rejected[] = ['index' => $index, 'reason' => $reason];
            $this->logger->warning('Assinatura externa: âncora de confiança ignorada.', ['index' => $index, 'reason' => $reason]);
        }

        return ['paths' => $paths, 'configured' => count($anchors), 'pinned' => count($paths), 'rejected' => $rejected];
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->resolve()['paths'];
    }

    public static function fingerprint(string $contents): ?string
    {
        $pem = str_contains($contents, '-----BEGIN CERTIFICATE-----')
            ? $contents
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($contents), 64, "\n")."-----END CERTIFICATE-----\n";
        $certificate = @openssl_x509_read($pem);

        if ($certificate === false) {
            while (openssl_error_string() !== false) {
                // Esvazia a fila de erros do OpenSSL.
            }

            return null;
        }

        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        return is_string($fingerprint) ? strtolower($fingerprint) : null;
    }

    private function check(string $path, string $pin): ?string
    {
        if ($path === '' || preg_match('/^[0-9a-f]{64}$/', $pin) !== 1) {
            return 'invalid_configuration';
        }

        if (! is_file($path) || ! is_readable($path)) {
            return 'file_not_found';
        }

        $contents = (string) @file_get_contents($path);

        if (substr_count($contents, '-----BEGIN CERTIFICATE-----') > 1) {
            return 'multiple_certificates';
        }

        $fingerprint = self::fingerprint($contents);

        if ($fingerprint === null) {
            return 'not_a_certificate';
        }

        return hash_equals($pin, $fingerprint) ? null : 'fingerprint_mismatch';
    }
}
