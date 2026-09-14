<?php

namespace App\Services\Ltv;

use App\Services\Ltv\Exceptions\LtvException;
use App\Services\Ltv\Models\LtvOperation;
use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\Exceptions\TsaUnavailableException;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Timestamp\OperatorTsa;
use App\Services\Timestamp\OperatorTsaSerials;
use App\Services\Timestamp\PadesProfilePolicy;
use App\Services\Timestamp\TsaToolRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assinatura PAdES com carimbo (B-T), informações de validação no DSS (B-LT) e carimbo de
 * documento (B-LTA) — `pdftool ltv-sign`, atrás de `pades_ltv` (que exige `operator_tsa`).
 *
 * - O perfil DECLARADO devolvido é sempre PAdES-B-B (T2); o nível técnico sai em `ltv_status`.
 * - Os seriais da TSA da operadora são reservados no banco ANTES do pdftool (um por carimbo:
 *   B-T/B-LT = 1, B-LTA = 2), cada um usado no máximo uma vez; o que sobrar numa degradação é
 *   marcado `failed/not_used` e nunca volta.
 * - Degradação explícita (R5): a etapa que falha não desfaz as anteriores; o resultado e cada
 *   falha ficam em `ltv_operations` e no log (sem segredo).
 * - As senhas (certificado e TSA) só existem nas variáveis NOMEADAS, injetadas no processo filho.
 *
 * Integração pendente (fora da área P3-LTV): a finalização (`OperatorSignature`) e o pipeline do
 * participante chamam este serviço no lugar de `sign` quando `LtvFeatures::enabled()`, e gravam o
 * relatório com {@see LtvState::apply()}.
 */
final class LtvSigner
{
    public function __construct(
        private readonly OperatorTsa $tsa,
        private readonly OperatorTsaSerials $serials,
        private readonly TsaToolRunner $runner,
        private readonly LtvConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return LtvFeatures::enabled() && $this->tsa->isAvailable();
    }

    /**
     * @param  array{reason?: string|null, location?: string|null, field_name?: string|null}  $options
     * @return array{declared_profile: string, ltv_status: LtvStatus, requested_level: string, effective_level: string, degraded: bool, degradations: list<array<string, string>>, operation: LtvOperation, report: array<string, mixed>}
     */
    public function sign(
        string $in,
        string $out,
        string $pfxPath,
        string $pfxPasswordEnv,
        ?string $level = null,
        array $options = [],
        ?int $organizationId = null,
        ?int $envelopeId = null,
        ?string $correlationId = null,
    ): array {
        if (! LtvFeatures::enabled()) {
            throw LtvException::disabled();
        }

        $reasons = $this->tsa->unavailableReasons();

        if ($reasons !== []) {
            throw new TsaUnavailableException($reasons);
        }

        $correlationId ??= (string) Str::ulid();
        $requested = $level !== null && in_array(strtoupper($level), LtvConfig::LEVELS, true) ? strtoupper($level) : $this->config->level();
        $asked = $requested;
        $preDegradations = [];

        if ($requested !== 'B-T' && $this->config->trustRoots() === []) {
            $asked = 'B-T';
            $preDegradations[] = [
                'step' => 'validation_info',
                'code' => 'trust_roots_not_configured',
                'message' => 'Sem raízes de confiança configuradas: B-LT/B-LTA indisponível, assinatura pedida em B-T.',
            ];
        }

        $tsaConfig = $this->tsa->config();
        $issuances = [$this->serials->reserve('ltv_signature', $organizationId, $correlationId)];

        if ($asked === 'B-LTA') {
            $issuances[] = $this->serials->reserve('ltv_document', $organizationId, $correlationId);
        }

        $args = [
            '--in', $in,
            '--out', $out,
            '--pfx', $pfxPath,
            '--pass-env', $pfxPasswordEnv,
            '--level', $asked,
            '--tsa-kind', 'operator',
            '--tsa-pfx', (string) $tsaConfig->pfxPath(),
            '--tsa-pass-env', $tsaConfig->passwordEnv(),
            '--tsa-policy-oid', $tsaConfig->policyOid(),
            '--tsa-accuracy-ms', (string) $tsaConfig->accuracyMs(),
        ];

        foreach ($issuances as $issuance) {
            array_push($args, '--tsa-serial', $issuance->serial);
        }

        foreach (['field_name' => '--field-name', 'reason' => '--reason', 'location' => '--location'] as $key => $flag) {
            $value = $options[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                array_push($args, $flag, $value);
            }
        }

        $args = [...$args, ...$this->config->revinfoArguments()];
        $secrets = array_values(array_unique([$pfxPasswordEnv, $tsaConfig->passwordEnv()]));

        try {
            $result = $this->runner->run('ltv-sign', $args, $secrets, $this->config->timeout(), null, $correlationId);
        } catch (Throwable $exception) {
            $code = $exception instanceof TsaException ? $exception->errorCode : 'unexpected_error';

            foreach ($issuances as $issuance) {
                $this->serials->markFailed($issuance, $code);
            }

            throw $exception;
        }

        $serials = $this->settleSerials($issuances, $result);
        $degradations = [...$preDegradations, ...self::degradationsFrom($result)];
        $effective = is_string($result['effective_level'] ?? null) ? $result['effective_level'] : 'B-B';
        $degraded = $degradations !== [];

        $operation = LtvOperation::query()->create([
            'ulid' => (string) Str::ulid(),
            'kind' => LtvOperation::KIND_SIGN,
            'idempotency_key' => 'sign:'.Str::ulid(),
            'organization_id' => $organizationId,
            'envelope_id' => $envelopeId,
            'status' => $degraded ? LtvOperation::STATUS_DEGRADED : LtvOperation::STATUS_COMPLETED,
            'requested_level' => $requested,
            'effective_level' => $effective,
            'degradations' => $degradations,
            'serials' => $serials,
            'result_sha256' => is_string($result['sha256'] ?? null) ? $result['sha256'] : null,
            'attempts' => 1,
            'correlation_id' => $correlationId,
            'started_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
        ]);

        if ($degraded) {
            $this->logger->warning('LTV: degradação explícita na assinatura de longo prazo.', [
                'requested_level' => $requested,
                'effective_level' => $effective,
                'steps' => array_map(fn (array $d): string => ($d['step'] ?? '?').':'.($d['code'] ?? '?'), $degradations),
                'ltv_operation' => $operation->ulid,
                'correlation_id' => $correlationId,
            ]);
        }

        return [
            // T2: o anunciado é SEMPRE o declarado, nunca o nível técnico.
            'declared_profile' => PadesProfilePolicy::declaredProfile(),
            'ltv_status' => LtvStatus::fromLevel($effective),
            'requested_level' => $requested,
            'effective_level' => $effective,
            'degraded' => $degraded,
            'degradations' => $degradations,
            'operation' => $operation,
            'report' => $result,
        ];
    }

    /**
     * @param  list<OperatorTsaIssuance>  $issuances
     * @param  array<string, mixed>  $result
     * @return array{used: list<string>, unused: list<string>}
     */
    private function settleSerials(array $issuances, array $result): array
    {
        $issued = [];

        foreach (is_array($result['timestamps'] ?? null) ? $result['timestamps'] : [] as $stamp) {
            if (is_array($stamp) && isset($stamp['serial'])) {
                $issued[(string) $stamp['serial']] = $stamp;
            }
        }

        $used = [];
        $unused = [];

        foreach ($issuances as $issuance) {
            if (isset($issued[$issuance->serial])) {
                if ($issuance->status === OperatorTsaIssuance::STATUS_RESERVED) {
                    /** @var array<string, mixed> $info */
                    $info = $issued[$issuance->serial];
                    $this->serials->markGranted($issuance, $info);
                }
                $used[] = $issuance->serial;
            } else {
                $this->serials->markFailed($issuance, 'not_used');
                $unused[] = $issuance->serial;
            }
        }

        return ['used' => $used, 'unused' => $unused];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, string>>
     */
    private static function degradationsFrom(array $result): array
    {
        $out = [];

        foreach (is_array($result['degradations'] ?? null) ? $result['degradations'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $out[] = [
                'step' => (string) ($item['step'] ?? 'unknown'),
                'code' => (string) ($item['code'] ?? 'unknown'),
                'message' => mb_substr((string) ($item['message'] ?? ''), 0, 300),
            ];
        }

        return $out;
    }
}
