<?php

namespace App\Services\Timestamp;

/**
 * Confere um token RFC 3161 contra um resumo com `pdftool tsa-verify`, usando as raízes
 * configuradas em `assinavelox.tsa.trust_roots`. A parte criptográfica é do pyHanko (não do
 * código que emitiu), e o critério de aceite externo continua sendo `openssl ts -verify`
 * (o README do dossiê ensina o comando).
 *
 * Sem raiz configurada, `trusted` é sempre false com motivo — nunca se afirma confiança.
 */
final class TimestampVerifier
{
    public function __construct(
        private readonly TsaToolRunner $runner,
        private readonly OperatorTsaConfig $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(string $tokenDer, string $digestHex, ?string $hashAlgorithm = null, ?string $expectedPolicy = null): array
    {
        $workDir = $this->runner->temporaryDirectory('tsa-verify-');

        try {
            file_put_contents($workDir->path('token.der'), $tokenDer, LOCK_EX);

            $args = ['--token', $workDir->path('token.der'), '--digest', strtolower($digestHex)];

            if ($hashAlgorithm !== null) {
                $args[] = '--hash-alg';
                $args[] = strtolower($hashAlgorithm);
            }

            if ($expectedPolicy !== null) {
                $args[] = '--policy-oid';
                $args[] = $expectedPolicy;
            }

            foreach ($this->config->trustRoots() as $root) {
                $args[] = '--trust';
                $args[] = $root;
            }

            return $this->runner->run('tsa-verify', $args, [], null, $workDir);
        } finally {
            $workDir->delete();
        }
    }

    /**
     * Resumo estável para gravar em `timestamp_tokens.verification` e no dossiê.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function summary(array $result): array
    {
        return [
            'valid' => (bool) ($result['valid'] ?? false),
            'trusted' => (bool) ($result['trusted'] ?? false),
            'trust_reason' => $result['trust_reason'] ?? null,
            'imprint_matches' => (bool) ($result['imprint_matches'] ?? false),
            'trust_roots_configured' => (int) ($result['trust_roots_configured'] ?? 0),
            'revocation' => 'not_checked',
            'errors' => array_values(array_map('strval', (array) ($result['errors'] ?? []))),
            'checked_at' => now('UTC')->toIso8601ZuluString(),
        ];
    }
}
