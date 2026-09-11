<?php

namespace App\Services\Timestamp;

use App\Services\Pdf\PdfToolClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;

/**
 * Leitura da configuração da TSA da operadora (`config('assinavelox.tsa')`).
 *
 * Nada aqui guarda ou devolve segredo: `password_env` é o NOME da variável; o valor só é
 * consultado para responder "está definido?" e é lido de novo, no momento do uso, pelo
 * runner que o injeta no processo filho.
 */
final class OperatorTsaConfig
{
    public const OID_PATTERN = '/^[0-2](\.(0|[1-9][0-9]*))+$/';

    /** OID de EXEMPLO da ITU-T X.667 — aceito em teste, recusado pelo `tsa:status` em produção. */
    public const EXAMPLE_POLICY_OID = '2.25.329800735698586629295641978511506172918';

    public function __construct(
        private readonly Repository $config,
        private readonly PdfToolClient $pdftool,
    ) {}

    public function pfxPath(): ?string
    {
        $path = trim((string) $this->config->get('assinavelox.tsa.pfx_path', ''));

        return $path === '' ? null : $path;
    }

    public function passwordEnv(): string
    {
        return (string) $this->config->get('assinavelox.tsa.password_env', 'ASSINAVELOX_TSA_PASSWORD');
    }

    public function passwordIsSet(): bool
    {
        $name = $this->passwordEnv();

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return false;
        }

        $value = Env::getRepository()->get($name);

        return is_string($value) && $value !== '';
    }

    public function policyOid(): string
    {
        return trim((string) $this->config->get('assinavelox.tsa.policy_oid', self::EXAMPLE_POLICY_OID));
    }

    public function accuracyMs(): int
    {
        return max(0, min(60_000, (int) $this->config->get('assinavelox.tsa.accuracy_ms', 1000)));
    }

    public function serialOffset(): int
    {
        return max(0, (int) $this->config->get('assinavelox.tsa.serial_offset', 0));
    }

    /**
     * `production` só quando dito explicitamente; qualquer outro valor é `test`.
     */
    public function environment(): string
    {
        return $this->config->get('assinavelox.tsa.environment') === 'production' ? 'production' : 'test';
    }

    /**
     * @return list<string>
     */
    public function trustRoots(): array
    {
        $roots = (array) $this->config->get('assinavelox.tsa.trust_roots', []);

        return array_values(array_filter(array_map('strval', $roots), fn (string $path): bool => $path !== '' && is_file($path)));
    }

    public function chainPem(): ?string
    {
        $path = trim((string) $this->config->get('assinavelox.tsa.chain_pem', ''));

        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * Só os blocos `CERTIFICATE` do PEM da cadeia, reescritos (conteúdo público que pode sair
     * no dossiê). Qualquer outro bloco — em especial `PRIVATE KEY`, que o `openssl pkcs12
     * -nodes` grava no mesmo arquivo — é descartado. Null sem arquivo ou sem certificado.
     */
    public function chainCertificatesPem(): ?string
    {
        $path = $this->chainPem();

        if ($path === null) {
            return null;
        }

        $content = (string) @file_get_contents($path);

        if (preg_match_all('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $content, $matches) < 1) {
            return null;
        }

        $blocks = [];

        foreach ($matches[1] as $body) {
            $der = base64_decode((string) preg_replace('/\s+/', '', $body), true);

            if ($der === false || $der === '') {
                continue;
            }

            $blocks[] = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END CERTIFICATE-----';
        }

        return $blocks === [] ? null : implode("\n", $blocks)."\n";
    }

    /**
     * O PEM da cadeia configurado traz algum bloco de CHAVE (privada ou cifrada)? Sinal de
     * configuração errada — o dossiê descarta a chave, mas o arquivo não devia tê-la.
     */
    public function chainPemContainsKey(): bool
    {
        $path = $this->chainPem();

        if ($path === null) {
            return false;
        }

        return preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', (string) @file_get_contents($path)) === 1;
    }

    public function timeout(): int
    {
        return max(5, (int) $this->config->get('assinavelox.tsa.timeout_seconds', 30));
    }

    /**
     * O que falta para emitir (sem olhar a flag). Lista vazia = configurada.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];
        $pfx = $this->pfxPath();

        if ($pfx === null) {
            $missing[] = 'ASSINAVELOX_TSA_PFX_PATH não definido.';
        } elseif (! is_file($pfx)) {
            $missing[] = 'O arquivo PKCS#12 da TSA não existe no caminho configurado.';
        }

        if (! $this->passwordIsSet()) {
            $missing[] = sprintf('A variável de ambiente %s (senha do PKCS#12 da TSA) não está definida.', $this->passwordEnv());
        }

        if (preg_match(self::OID_PATTERN, $this->policyOid()) !== 1) {
            $missing[] = 'ASSINAVELOX_TSA_POLICY_OID não é um OID válido.';
        }

        if (! $this->pdftool->isAvailable()) {
            $missing[] = 'pdftool indisponível (venv do tools/pdftool).';
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missing() === [];
    }
}
