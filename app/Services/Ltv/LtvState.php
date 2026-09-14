<?php

namespace App\Services\Ltv;

use App\Models\VerificationRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Grava e lê o estado TÉCNICO de longo prazo em `verification_records.ltv_*` (P3-LTV).
 *
 * Nunca toca em `signature_profile` nem em `signature_status`: o perfil anunciado é decidido
 * só por {@see LtvProfilePolicy} (T2). O próximo re-carimbo é o vencimento do certificado da TSA
 * do último carimbo de arquivamento menos `ltv.refresh.margin_days`.
 */
final class LtvState
{
    public const ARCHIVE_LAYER_ONLY_LABEL = 'Carimbo de arquivamento da operadora sobre o arquivo; há assinaturas sem carimbo '
        .'nem informações de revogação próprios, então o nível do arquivo continua PAdES-B-B';

    public function __construct(private readonly LtvConfig $config) {}

    /**
     * @param  array<string, mixed>  $report  saída de `ltv-sign`, `ltv-refresh` ou `ltv-validate`
     */
    public function apply(VerificationRecord $record, array $report, ?CarbonInterface $now = null): LtvStatus
    {
        $now = Carbon::instance($now ?? Carbon::now());
        $level = $report['effective_level'] ?? null;

        // Dois fatos SEPARADOS (revisão adversarial I-3A):
        //
        // 1. o NÍVEL TÉCNICO do arquivo é o `effective_level` do pdftool — no re-carimbo, o MENOR
        //    nível entre as assinaturas. Um arquivo com assinaturas de participantes sem carimbo
        //    nem revogação próprios (A1, componente local, devolução do portal) é B-B, e é isso
        //    que fica em `ltv_status` (a única entrada de LtvProfilePolicy::displayProfile — T2);
        // 2. a CAMADA DE ARQUIVAMENTO da operadora (cadeia de carimbos de documento válida) existe
        //    e PRECISA continuar sendo renovada: o agendamento (`ltv_archive_expires_at` e
        //    `ltv_next_refresh_at`) vem dela, não do nível do arquivo.
        //
        // No `ltv-sign`, `effective_level` é o nível que a assinatura NOVA alcançou; o do arquivo
        // inteiro é `validated_level` (análise final) — é esse que vale como estado técnico.
        $built = LtvStatus::fromLevel(is_string($level) ? $level : null);
        $fileLevel = is_string($report['validated_level'] ?? null) ? $report['validated_level'] : $level;
        $status = LtvStatus::fromLevel(is_string($fileLevel) ? $fileLevel : null);

        $archiveLayer = $built->isArchival()
            || (array_key_exists('document_timestamps_after', $report)
                && ($report['timestamp_chain_valid'] ?? false) === true
                && is_array($report['archive_timestamp'] ?? null));

        $archive = is_array($report['archive_timestamp'] ?? null) ? $report['archive_timestamp'] : [];
        $expires = $archiveLayer ? self::parse($archive['tsa_cert_not_after'] ?? null) : null;

        // "Revogação embutida" só quando o NÍVEL DO ARQUIVO a garante para todas as assinaturas
        // (B-LT ou acima): um DSS presente pode cobrir só a assinatura da operadora.
        $fileCoversRevocation = in_array($status, [LtvStatus::BLt, LtvStatus::BLta], true);

        if (array_key_exists('revocation_embedded', $report)) {
            $embedded = $report['revocation_embedded'] === true && $fileCoversRevocation;
        } else {
            $dss = is_array($report['dss'] ?? null) ? $report['dss'] : [];
            $embedded = ($dss['present'] ?? false) === true && $fileCoversRevocation;
        }

        $next = null;

        if ($expires !== null) {
            $next = $expires->copy()->subDays($this->config->refreshMarginDays());

            if ($next->lessThan($now)) {
                $next = $now->copy();
            }
        }

        $record->forceFill([
            'ltv_status' => $status->value,
            'ltv_last_timestamp_at' => self::storable(self::parse($report['last_timestamp_at'] ?? null)),
            'ltv_revocation_embedded' => $embedded,
            'ltv_archive_expires_at' => self::storable($expires),
            'ltv_next_refresh_at' => self::storable($next),
            'ltv_checked_at' => self::storable($now),
        ])->save();

        return $status;
    }

    /**
     * O arquivo tem uma camada de arquivamento da operadora a renovar? (independente do nível
     * efetivo do arquivo — ver {@see self::apply()}).
     */
    public static function archiveLayer(VerificationRecord $record): bool
    {
        return $record->getAttribute('ltv_archive_expires_at') !== null;
    }

    public static function status(VerificationRecord $record): LtvStatus
    {
        $raw = $record->getAttribute('ltv_status');

        return LtvStatus::tryFrom(is_string($raw) ? $raw : '') ?? LtvStatus::NotApplicable;
    }

    /**
     * Visão INTERNA (página autenticada de evidências / operação). Não é para a página pública.
     *
     * @return array{status: string, label: string, level: string|null, archive_layer: bool, last_timestamp_at: string|null, revocation_embedded: bool, archive_expires_at: string|null, next_refresh_at: string|null, checked_at: string|null, tsa_kind: string, announced: bool, announced_profile: string|null, notice: string}
     */
    public static function view(VerificationRecord $record): array
    {
        $status = self::status($record);
        $announced = LtvProfilePolicy::displayProfile($record->signature_profile, $status);
        $archiveLayer = self::archiveLayer($record);

        return [
            'status' => $status->value,
            'label' => $status === LtvStatus::NotApplicable && $archiveLayer ? self::ARCHIVE_LAYER_ONLY_LABEL : $status->label(),
            'level' => $status->level(),
            'archive_layer' => $archiveLayer,
            'last_timestamp_at' => self::parse($record->getAttribute('ltv_last_timestamp_at'))?->toIso8601String(),
            'revocation_embedded' => (bool) $record->getAttribute('ltv_revocation_embedded'),
            'archive_expires_at' => self::parse($record->getAttribute('ltv_archive_expires_at'))?->toIso8601String(),
            'next_refresh_at' => self::parse($record->getAttribute('ltv_next_refresh_at'))?->toIso8601String(),
            'checked_at' => self::parse($record->getAttribute('ltv_checked_at'))?->toIso8601String(),
            'tsa_kind' => 'operator',
            'announced' => $status !== LtvStatus::NotApplicable && $announced !== $record->signature_profile,
            'announced_profile' => $announced,
            'notice' => LtvStatus::NOT_ANNOUNCED_NOTICE,
        ];
    }

    private static function parse(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function storable(?Carbon $value): ?string
    {
        return $value?->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }
}
