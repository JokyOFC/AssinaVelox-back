<?php

namespace App\Integrations\LocalSigner;

use App\Enums\LocalSignerComponent;
use App\Integrations\LocalSigner\Contracts\LocalSignerBridge;
use App\Integrations\LocalSigner\Dto\LocalSignerCertificate;
use App\Integrations\LocalSigner\Dto\LocalSignerSignature;
use App\Integrations\LocalSigner\Dto\LocalSignerStatus;
use App\Integrations\LocalSigner\Exceptions\LocalSignerUnavailable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * SIMULADOR de componente local (Fase 3 §3.4). **Nenhum token é usado.**
 *
 * Faz, no servidor, o papel que o NexU/Lacuna fariam na máquina do participante: devolve um
 * certificado e assina um digest pronto (RSA PKCS#1 v1.5 sobre SHA-256, sem novo hash) com a
 * chave de um PKCS#12 de TESTE. Serve para exercitar o fluxo inteiro (preparar → assinar
 * fora → embutir → validar) sem hardware.
 *
 * Travas:
 *
 * - só nos ambientes de `components.simulated.allowed_environments` (padrão `local` e
 *   `testing`) e com `components.simulated.enabled` ligado — em produção, `detect()` diz
 *   `environment_not_allowed` e nada assina;
 * - `isSimulated()` = true e `producesTokenSignatures()` = false SEMPRE: o que sai daqui é
 *   gravado como `participant_external` com `is_simulated`, rotulado
 *   "{@see self::LABEL}", nunca como A3;
 * - a senha do PKCS#12 vem da variável de ambiente NOMEADA na configuração (nunca do código,
 *   do banco ou de argumento), é marcada `#[\SensitiveParameter]` e não aparece em log,
 *   exceção ou resposta. A chave aberta vive só dentro de {@see self::signDigest()}.
 */
final class FakeLocalSigner implements LocalSignerBridge
{
    public const LABEL = 'simulado — nenhum token foi usado';

    public const VERSION = 'simulador-1';

    /** Prefixo DER do DigestInfo de SHA-256 (RFC 8017 §9.2, nota 1). */
    private const SHA256_DIGEST_INFO = '3031300d060960864801650304020105000420';

    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    public function component(): LocalSignerComponent
    {
        return LocalSignerComponent::Simulated;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function producesTokenSignatures(): bool
    {
        return false;
    }

    public function detect(): LocalSignerStatus
    {
        $reason = $this->unavailableReason();

        return new LocalSignerStatus(LocalSignerComponent::Simulated, $reason === null, true, false, self::VERSION, $reason);
    }

    public function signingCertificate(): LocalSignerCertificate
    {
        $bundle = $this->open();

        try {
            $certificate = self::pemToDer($bundle['cert']);
            $chain = array_map(static fn (string $pem): string => base64_encode(self::pemToDer($pem)), $bundle['extracerts']);

            return new LocalSignerCertificate(
                base64_encode($certificate),
                $chain,
                'RSA',
                'simulated:'.hash('sha256', $certificate),
                ['SHA256'],
                'SHA256',
                hash('sha256', $certificate),
            );
        } finally {
            $bundle['pkey'] = str_repeat("\0", strlen($bundle['pkey']));
            unset($bundle);
        }
    }

    public function signDigest(string $keyHandle, string $digest, string $hashFunction): LocalSignerSignature
    {
        if (strtoupper(str_replace('-', '', $hashFunction)) !== 'SHA256' || strlen($digest) !== 32) {
            throw new LocalSignerUnavailable('invalid_request', 'O simulador assina somente resumos SHA-256 (32 bytes).');
        }

        $bundle = $this->open();

        try {
            $certificate = self::pemToDer($bundle['cert']);

            if (! hash_equals('simulated:'.hash('sha256', $certificate), $keyHandle)) {
                throw new LocalSignerUnavailable('invalid_request', 'O identificador da chave não pertence ao certificado do simulador.');
            }

            $key = openssl_pkey_get_private($bundle['pkey']);
            $details = $key === false ? false : openssl_pkey_get_details($key);

            if ($key === false || $details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                throw new LocalSignerUnavailable('unsupported_key', 'O simulador aceita somente chave RSA.');
            }

            // Assinatura BRUTA sobre um digest pronto: RSA PKCS#1 v1.5 do DigestInfo (sem novo hash).
            $signature = '';

            if (! openssl_private_encrypt((string) hex2bin(self::SHA256_DIGEST_INFO).$digest, $signature, $key, OPENSSL_PKCS1_PADDING)) {
                throw new LocalSignerUnavailable('pfx_unreadable', 'O simulador não conseguiu assinar o resumo.');
            }

            return new LocalSignerSignature(base64_encode($signature), 'RSA_SHA256', base64_encode($certificate));
        } finally {
            $bundle['pkey'] = str_repeat("\0", strlen($bundle['pkey']));
            unset($bundle, $key);
        }
    }

    public function unavailableReason(): ?string
    {
        $settings = (array) $this->config->get('assinavelox.external_signing.components.simulated', []);

        if (! filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return 'disabled';
        }

        $allowed = array_values(array_filter((array) ($settings['allowed_environments'] ?? []), 'is_string'));

        if ($this->app->environment('production') || ! in_array($this->app->environment(), $allowed, true)) {
            return 'environment_not_allowed';
        }

        $path = (string) ($settings['pfx_path'] ?? '');

        if ($path === '' || ! is_file($path)) {
            return 'pfx_not_configured';
        }

        $env = (string) ($settings['pass_env'] ?? '');

        if ($env === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $env) !== 1 || (string) getenv($env) === '') {
            return 'passphrase_not_configured';
        }

        return null;
    }

    /**
     * @return array{cert: string, pkey: string, extracerts: list<string>}
     *
     * @throws LocalSignerUnavailable
     */
    private function open(): array
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            throw new LocalSignerUnavailable($reason, 'O simulador de componente local não está disponível neste ambiente.');
        }

        $settings = (array) $this->config->get('assinavelox.external_signing.components.simulated', []);
        $bytes = (string) @file_get_contents((string) $settings['pfx_path']);
        $bundle = $this->read($bytes, (string) getenv((string) $settings['pass_env']));
        $bytes = str_repeat("\0", strlen($bytes));

        return $bundle;
    }

    /**
     * @return array{cert: string, pkey: string, extracerts: list<string>}
     */
    private function read(string $bytes, #[\SensitiveParameter] string $password): array
    {
        $out = [];

        if ($bytes === '' || ! openssl_pkcs12_read($bytes, $out, $password)) {
            while (openssl_error_string() !== false) {
                // Esvazia a fila de erros do OpenSSL sem registrar nada (pode citar o arquivo).
            }

            throw new LocalSignerUnavailable('pfx_unreadable', 'O PKCS#12 de teste do simulador não pôde ser aberto.');
        }

        return [
            'cert' => (string) ($out['cert'] ?? ''),
            'pkey' => (string) ($out['pkey'] ?? ''),
            'extracerts' => array_values(array_filter((array) ($out['extracerts'] ?? []), 'is_string')),
        ];
    }

    public static function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END)[^-]+-----|\s+/', '', $pem);

        return (string) base64_decode((string) $body, true);
    }
}
