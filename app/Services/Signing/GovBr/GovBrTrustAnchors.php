<?php

namespace App\Services\Signing\GovBr;

use App\Services\Signing\GovBr\Exceptions\GovBrReturnException;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * Âncoras gov.br FIXADAS por impressão digital (docs/integracoes/gov-br-assinatura.md §6.3).
 *
 * `assinavelox.govbr.trust_roots` lista arquivos (PEM, com um ou mais certificados, ou DER) e
 * `assinavelox.govbr.trust_root_fingerprints` lista o SHA-256 (hex) de CADA certificado
 * aceito. Um arquivo só vale se TODOS os certificados dele estiverem na lista — trocar o
 * arquivo no disco não troca a âncora. Nada é baixado em tempo de execução.
 *
 * - sem arquivo configurado → "não configurado": a devolução ainda pode ser aceita, mas com o
 *   rótulo "assinatura digital de terceiro, cadeia não verificada" (nunca "gov.br");
 * - arquivo configurado sem impressão digital correspondente (ou ilegível) → configuração
 *   inválida: a devolução é RECUSADA com 503, em vez de cair silenciosamente no rótulo sem
 *   verificação ou, pior, confiar numa raiz trocada.
 */
final class GovBrTrustAnchors
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function configured(): bool
    {
        return $this->configuredPaths() !== [];
    }

    /**
     * Caminhos das âncoras para o `pdftool --trust`, já conferidos contra as impressões digitais.
     *
     * @return list<string>
     *
     * @throws GovBrReturnException configuração inválida
     */
    public function verifiedPaths(): array
    {
        $paths = $this->configuredPaths();

        if ($paths === []) {
            return [];
        }

        $pins = $this->pins();

        if ($pins === []) {
            $this->misconfigured('trust_roots configured without trust_root_fingerprints');
        }

        foreach ($paths as $path) {
            $fingerprints = self::fingerprintsOfFile($path);

            if ($fingerprints === []) {
                $this->misconfigured('trust root file unreadable or without certificates');
            }

            foreach ($fingerprints as $fingerprint) {
                if (! in_array($fingerprint, $pins, true)) {
                    $this->misconfigured('trust root certificate not pinned');
                }
            }
        }

        return $paths;
    }

    /**
     * SHA-256 (hex minúsculo) de cada certificado do arquivo (PEM com um ou mais blocos, ou DER).
     *
     * @return list<string>
     */
    public static function fingerprintsOfFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $data = (string) file_get_contents($path);
        $blocks = [];

        if (preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $data, $matches) > 0) {
            foreach ($matches[1] as $body) {
                $der = base64_decode((string) preg_replace('/\s+/', '', $body), true);

                if (is_string($der) && $der !== '') {
                    $blocks[] = $der;
                }
            }
        } elseif ($data !== '') {
            $blocks[] = $data;
        }

        $fingerprints = [];

        foreach ($blocks as $der) {
            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
            $certificate = @openssl_x509_read($pem);

            if ($certificate === false) {
                return [];
            }

            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

            if (! is_string($fingerprint) || $fingerprint === '') {
                return [];
            }

            $fingerprints[] = strtolower($fingerprint);
        }

        return $fingerprints;
    }

    /**
     * @return list<string>
     */
    private function configuredPaths(): array
    {
        $paths = [];

        foreach ((array) $this->config->get('assinavelox.govbr.trust_roots', []) as $path) {
            if (is_string($path) && trim($path) !== '') {
                $paths[] = trim($path);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function pins(): array
    {
        $pins = [];

        foreach ((array) $this->config->get('assinavelox.govbr.trust_root_fingerprints', []) as $pin) {
            $normalized = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', (string) $pin));

            if (strlen($normalized) === 64) {
                $pins[] = $normalized;
            }
        }

        return array_values(array_unique($pins));
    }

    /**
     * @throws GovBrReturnException
     */
    private function misconfigured(string $reason): never
    {
        $this->logger->error('gov.br (devolução): âncoras de confiança mal configuradas; devoluções recusadas até corrigir.', [
            'reason' => $reason,
            'alert' => 'govbr_trust_anchor_misconfigured',
        ]);

        throw new GovBrReturnException(
            'trust_anchor_misconfigured',
            'A conferência de assinaturas do portal está indisponível no momento. Tente mais tarde.',
            503,
        );
    }
}
