<?php

namespace App\Services\Identity;

use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Integrations\Cpf\CpfVerificationFactory;
use App\Integrations\Cpf\FakeCpfVerificationProvider;
use App\Integrations\Cpf\OwnServiceCpfVerificationProvider;
use App\Models\SigningField;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Consulta CADASTRAL do CPF digitado no aceite (classe B — docs/fase-2/identidade.md §2.2).
 *
 * Só roda com a flag `cpf_lookup` da organização. O resultado é gravado no `fields_snapshot`
 * do aceite como `{status, provider, simulated, checked_at, reason_code, label}` e na trilha
 * com o CPF MASCARADO. Nome, nascimento e óbito que um provedor devolva nunca são guardados.
 *
 * | provedor      | aqui           | significado                                              |
 * |---------------|----------------|----------------------------------------------------------|
 * | `valid`       | `verified`     | consulta cadastral positiva no provedor X no momento T   |
 * | `invalid`     | `not_verified` | não confirmado (não encontrado, divergente, irregular)   |
 * | `inconclusive`| `unavailable`  | sem resposta confiável (tempo esgotado, não configurado) |
 *
 * **Nenhum resultado bloqueia o aceite** (roadmap §2.11; o modo "estrito" é decisão pendente).
 * `verified` NUNCA quer dizer que a pessoa é a titular: o provedor confere o número, não
 * quem digitou.
 */
final class CpfLookup
{
    public const DISCLAIMER = 'A consulta cadastral confere o número do CPF na base do provedor. Ela não confirma que quem preencheu o campo é o titular do CPF.';

    public function __construct(
        private readonly CpfVerificationFactory $providers,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  Collection<int, SigningField>  $fields
     * @param  array<string, array{text: string|null, bool: bool|null}>  $values
     * @return array<string, array{status: string, provider: string, simulated: bool, checked_at: string, reason_code: string|null, label: string}> por ULID do campo
     */
    public function checkFields(Collection $fields, array $values, SignerContext $context, string $correlationId): array
    {
        if (! IdentityFeatures::cpfLookup($context->organization)) {
            return [];
        }

        $checks = [];

        foreach ($fields as $field) {
            if ($field->type !== FieldType::Cpf) {
                continue;
            }

            $text = $values[$field->ulid]['text'] ?? null;

            if (! is_string($text) || ! CpfNumber::isValid($text)) {
                continue;
            }

            $checks[$field->ulid] = $this->check($text, $context, $field->ulid, $correlationId);
        }

        return $checks;
    }

    /**
     * @return array{status: string, provider: string, simulated: bool, checked_at: string, reason_code: string|null, label: string}
     */
    public function check(string $cpf, SignerContext $context, string $fieldUlid, string $correlationId): array
    {
        $digits = CpfNumber::digits($cpf);
        $provider = $this->providers->make();

        try {
            // Adaptador não configurado (serviço próprio sem documentação, simulador não
            // autorizado nesta instalação): nenhuma chamada; resultado desconhecido.
            $response = $provider->isConfigured() || $provider instanceof OwnServiceCpfVerificationProvider
                ? $provider->verify($digits, ['purpose' => (string) config('assinavelox.cpf_lookup.purpose', '')], $correlationId)
                : ['status' => 'inconclusive', 'provider' => $provider->name(), 'checked_at' => Carbon::now()->toIso8601String(), 'details' => ['reason_code' => 'not_configured']];
        } catch (\Throwable $exception) {
            // Qualquer falha do adaptador é resultado DESCONHECIDO (T5), nunca sucesso.
            $this->logger->warning('Consulta cadastral de CPF falhou; registrada como indisponível.', [
                'cpf' => CpfNumber::mask($digits),
                'provider' => $provider->name(),
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);

            $response = ['status' => 'inconclusive', 'provider' => $provider->name(), 'checked_at' => Carbon::now()->toIso8601String(), 'details' => ['reason_code' => 'provider_error']];
        }

        $details = $response['details'] ?? [];
        $status = match ($response['status']) {
            'valid' => 'verified',
            'invalid' => 'not_verified',
            default => 'unavailable',
        };
        $rawReason = $details['reason_code'] ?? $details['reason'] ?? null;
        $reason = is_string($rawReason) ? mb_substr($rawReason, 0, 40) : null;
        $simulated = $provider->name() === FakeCpfVerificationProvider::NAME || ($details['simulated'] ?? false) === true;

        $result = [
            'status' => $status,
            'provider' => $provider->name(),
            'simulated' => $simulated,
            'checked_at' => Carbon::now()->toIso8601String(),
            'reason_code' => $reason,
            'label' => self::label($status, $simulated),
        ];

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::CpfLookupPerformed, [
            'field_ulid' => $fieldUlid,
            'cpf_masked' => CpfNumber::mask($digits),
            'status' => $status,
            'provider' => $result['provider'],
            'simulated' => $simulated,
            'reason_code' => $reason,
        ], $correlationId);

        return $result;
    }

    public static function label(string $status, bool $simulated): string
    {
        $label = match ($status) {
            'verified' => 'Consulta cadastral: CPF encontrado em situação regular',
            'not_verified' => 'Consulta cadastral: CPF não confirmado pelo provedor',
            default => 'Consulta cadastral indisponível: o aceite seguiu sem ela',
        };

        return $simulated ? $label.' (simulado)' : $label;
    }
}
