<?php

namespace App\Services\Risk;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contagens de curta duração que nenhuma tabela do domínio guarda (cadastros por rede e por
 * dispositivo declarado). Só o HMAC do sujeito; o que passa de
 * `observation_retention_days` é apagado a cada gravação (poda incremental, sem agendador).
 */
final class RiskObservations
{
    public const SIGNUP_IP = 'signup_ip';

    public const SIGNUP_DEVICE = 'signup_device';

    public function record(string $kind, string $subjectKey, ?int $organizationId): void
    {
        $now = Carbon::now();

        DB::table('risk_observations')->insert([
            'kind' => $kind,
            'subject_key' => $subjectKey,
            'organization_id' => $organizationId,
            'observed_at' => $now,
        ]);

        $cutoff = $now->copy()->subDays(max(1, (int) config('assinavelox.risk.observation_retention_days', 30)));

        DB::table('risk_observations')->where('observed_at', '<', $cutoff)->limit(500)->delete();
    }

    public function count(string $kind, string $subjectKey, int $windowMinutes): int
    {
        return DB::table('risk_observations')
            ->where('kind', $kind)
            ->where('subject_key', $subjectKey)
            ->where('observed_at', '>=', Carbon::now()->subMinutes($windowMinutes))
            ->count();
    }
}
