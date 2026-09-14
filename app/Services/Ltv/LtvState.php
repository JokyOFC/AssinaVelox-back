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
    public function __construct(private readonly LtvConfig $config) {}

    /**
     * @param  array<string, mixed>  $report  saída de `ltv-sign`, `ltv-refresh` ou `ltv-validate`
     */
    public function apply(VerificationRecord $record, array $report, ?CarbonInterface $now = null): LtvStatus
    {
        $now = Carbon::instance($now ?? Carbon::now());
        $level = $report['effective_level'] ?? null;
        $status = LtvStatus::fromLevel(is_string($level) ? $level : null);

        $archive = is_array($report['archive_timestamp'] ?? null) ? $report['archive_timestamp'] : [];
        $expires = $status->isArchival() ? self::parse($archive['tsa_cert_not_after'] ?? null) : null;

        if (array_key_exists('revocation_embedded', $report)) {
            $embedded = $report['revocation_embedded'] === true;
        } else {
            $dss = is_array($report['dss'] ?? null) ? $report['dss'] : [];
            $embedded = ($dss['present'] ?? false) === true && in_array($status, [LtvStatus::BLt, LtvStatus::BLta], true);
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

    public static function status(VerificationRecord $record): LtvStatus
    {
        $raw = $record->getAttribute('ltv_status');

        return LtvStatus::tryFrom(is_string($raw) ? $raw : '') ?? LtvStatus::NotApplicable;
    }

    /**
     * Visão INTERNA (página autenticada de evidências / operação). Não é para a página pública.
     *
     * @return array{status: string, label: string, level: string|null, last_timestamp_at: string|null, revocation_embedded: bool, archive_expires_at: string|null, next_refresh_at: string|null, checked_at: string|null, tsa_kind: string, announced: bool, announced_profile: string|null, notice: string}
     */
    public static function view(VerificationRecord $record): array
    {
        $status = self::status($record);
        $announced = LtvProfilePolicy::displayProfile($record->signature_profile, $status);

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'level' => $status->level(),
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
