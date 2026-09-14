<?php

namespace App\Integrations\LocalSigner;

use App\Enums\LocalSignerComponent;
use App\Integrations\LocalSigner\Contracts\LocalSignerBridge;
use App\Integrations\LocalSigner\Dto\LocalSignerCertificate;
use App\Integrations\LocalSigner\Dto\LocalSignerSignature;
use App\Integrations\LocalSigner\Dto\LocalSignerStatus;
use App\Integrations\LocalSigner\Exceptions\LocalSignerUnavailable;
use Illuminate\Contracts\Config\Repository;

/**
 * NexU (fork comunitário `p4535992/nexu`, EUPL-1.2) — **produção DESABILITADA** (classe B da
 * viabilidade; docs/integracoes/a3-componente-local.md §3–§8; risco R4).
 *
 * ## Protocolo (fork 1.25, confirmado no código-fonte — brief §3.4)
 *
 * Quem fala com o NexU é o NAVEGADOR do participante (o servidor não alcança o
 * `127.0.0.1` da máquina dele):
 *
 * | Método e rota                  | Envio                                                 | Resposta                                                                                  |
 * |--------------------------------|-------------------------------------------------------|-------------------------------------------------------------------------------------------|
 * | `GET /v1/status`               | —                                                     | `applicationVersion`, `protocolVersion`, `features[]`                                     |
 * | `POST /v1/signing-certificate` | `closeToken?`, `certificatePurpose?`, `nonRepudiation?` | `certificate` (Base64 DER), `certificateChain[]`, `encryptionAlgorithm`, `supportedDigests[]`, `preferredDigest`, `keyHandle {tokenId, keyId}` |
 * | `POST /v1/sign`                | `keyHandle`, `hash` (Base64 do digest), `hashFunction` (`SHA256`), `clearToken?` | `signature` (Base64, BRUTA), `signatureAlgorithm`, `certificate`, `certificateChain`       |
 *
 * `/v1/sign` assina o digest SEM novo hash — casa com o modo `raw` do
 * `pdftool embed-external` (a plataforma monta o CMS). Endereços: HTTP `127.0.0.1:9795`,
 * HTTPS `127.0.0.1:9895` (TLS local autoassinado, confiança manual no navegador).
 *
 * ## Por que desabilitado
 *
 * - **CORS**: `/v1/**` exige allowlist de origem no `nexu-config.properties` DA MÁQUINA DO
 *   PARTICIPANTE; curinga é recusado. Os legados `/rest/*` com `*` deixam qualquer site pedir
 *   operações ao token — não serão usados.
 * - **Local Network Access** (Chrome 142+): toda chamada a loopback pede permissão ao
 *   usuário; o NexU não emite `Access-Control-Allow-Private-Network` — interação NÃO testada.
 * - Proveniência: o repositório da Nowina sumiu; o fork é de uma pessoa, releases num único
 *   dia, sem macOS.
 *
 * ## O que falta para ligar
 *
 * {@see self::missingForProduction()}. Enquanto {@see self::PRODUCTION_ENABLED} for `false`,
 * `detect()` responde `production_disabled` e o servidor recusa preparar/aceitar assinatura
 * declarada como vinda do NexU.
 */
final class NexuLocalSigner implements LocalSignerBridge
{
    public const PRODUCTION_ENABLED = false;

    public function __construct(private readonly Repository $config) {}

    public function component(): LocalSignerComponent
    {
        return LocalSignerComponent::Nexu;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function producesTokenSignatures(): bool
    {
        return true;
    }

    public function detect(): LocalSignerStatus
    {
        // Enquanto PRODUCTION_ENABLED for false, o motivo é sempre `production_disabled`. Ligar o
        // componente exige mudar este método junto (e cumprir missingForProduction()).
        return new LocalSignerStatus(
            LocalSignerComponent::Nexu,
            self::PRODUCTION_ENABLED,
            false,
            self::PRODUCTION_ENABLED,
            null,
            'production_disabled',
        );
    }

    public function signingCertificate(): LocalSignerCertificate
    {
        throw LocalSignerUnavailable::productionDisabled('NexU');
    }

    public function signDigest(string $keyHandle, string $digest, string $hashFunction): LocalSignerSignature
    {
        throw LocalSignerUnavailable::productionDisabled('NexU');
    }

    /**
     * Descrição para o front (o `LocalSignerBridge` em TypeScript faz as chamadas).
     *
     * @return array<string, mixed>
     */
    public function protocol(): array
    {
        $settings = (array) $this->config->get('assinavelox.external_signing.components.nexu', []);

        return [
            'component' => LocalSignerComponent::Nexu->value,
            'production_enabled' => self::PRODUCTION_ENABLED,
            'http_base' => (string) ($settings['http_base'] ?? 'http://127.0.0.1:9795'),
            'https_base' => (string) ($settings['https_base'] ?? 'https://127.0.0.1:9895'),
            'minimum_version' => (string) ($settings['minimum_version'] ?? '1.25.0'),
            'endpoints' => [
                'status' => ['method' => 'GET', 'path' => '/v1/status'],
                'signing_certificate' => ['method' => 'POST', 'path' => '/v1/signing-certificate'],
                'sign' => ['method' => 'POST', 'path' => '/v1/sign', 'hash_function' => 'SHA256', 'mode' => 'raw'],
            ],
            'browser_notes' => [
                'O navegador pedirá permissão de acesso à rede local (Chrome 142 ou mais recente); sem ela, o componente não é alcançado.',
                'O componente usa um certificado TLS local autoassinado, que precisa ser aceito no navegador.',
                'A origem desta plataforma precisa estar autorizada na configuração do componente, na máquina do participante.',
            ],
            'missing_for_production' => self::missingForProduction(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function missingForProduction(): array
    {
        return [
            'Piloto no Windows com pelo menos dois modelos de token A3 comuns no Brasil, em Chrome e Firefox, cobrindo a permissão de rede local e o TLS local.',
            'Escolha do componente: NexU (fork) com instalador próprio e parecer sobre a EUPL-1.2, Assinador Serpro (licença e formato do comando de hash) ou Lacuna/BRy (contrato).',
            'Parecer jurídico sobre redistribuir o NexU (obra derivada sob EUPL-1.2) e plano de manutenção do fork.',
            'Validação da cadeia até as raízes ICP-Brasil do ITI fixadas por impressão digital, com LCR/OCSP atualizadas por job.',
            'Validação da assinatura gerada em validador externo independente (Verificador de Conformidade do ITI).',
        ];
    }
}
