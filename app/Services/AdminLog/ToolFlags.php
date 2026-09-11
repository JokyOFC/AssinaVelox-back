<?php

namespace App\Services\AdminLog;

use App\Models\Organization;
use App\Models\Plan;

/**
 * Flags de ativação da área "etiquetas, relatórios e logs" (Fase 2, roadmap §1 T8 — todas
 * desligadas por padrão).
 *
 * Flags DA ORGANIZAÇÃO (`tags`, `reports`, `audit_log`) valem quando as DUAS fontes dizem
 * sim — mesma regra de App\Services\Envelopes\DomainFeatures:
 *  1. `config('assinavelox.features.{flag}')` (interruptor da operadora, padrão false);
 *  2. `plans.features.{flag}` do plano vigente da organização.
 *
 * Flags DA PLATAFORMA (`admin_users`, `admin_audit`, `impersonation`) só dependem da
 * configuração global: o painel interno não tem organização corrente.
 *
 * A flag liga a interface e as rotas novas; a autorização continua nas permissões
 * (App\Enums\Permission) e no middleware `platform-admin`.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora desta área): as chaves `tags`,
 * `reports` e `audit_log` devem vir de {@see self::forOrganization()} para a organização
 * corrente. Enquanto isso, as páginas desta área recebem a flag como prop própria.
 */
final class ToolFlags
{
    public const TAGS = 'tags';

    public const REPORTS = 'reports';

    public const AUDIT_LOG = 'audit_log';

    public const ADMIN_USERS = 'admin_users';

    public const ADMIN_AUDIT = 'admin_audit';

    public const IMPERSONATION = 'impersonation';

    public static function tags(?Organization $organization): bool
    {
        return self::enabled(self::TAGS, $organization);
    }

    public static function reports(?Organization $organization): bool
    {
        return self::enabled(self::REPORTS, $organization);
    }

    public static function auditLog(?Organization $organization): bool
    {
        return self::enabled(self::AUDIT_LOG, $organization);
    }

    public static function adminUsers(): bool
    {
        return self::global(self::ADMIN_USERS);
    }

    public static function adminAudit(): bool
    {
        return self::global(self::ADMIN_AUDIT);
    }

    public static function impersonation(): bool
    {
        return self::global(self::IMPERSONATION);
    }

    /**
     * @return array{tags: bool, reports: bool, audit_log: bool}
     */
    public static function forOrganization(?Organization $organization): array
    {
        $plan = self::planOf($organization);

        return [
            self::TAGS => self::enabled(self::TAGS, $organization, $plan),
            self::REPORTS => self::enabled(self::REPORTS, $organization, $plan),
            self::AUDIT_LOG => self::enabled(self::AUDIT_LOG, $organization, $plan),
        ];
    }

    public static function enabled(string $flag, ?Organization $organization, ?Plan $plan = null): bool
    {
        if (! self::global($flag) || $organization === null) {
            return false;
        }

        $plan ??= self::planOf($organization);

        return is_array($plan?->features) && ($plan->features[$flag] ?? false) === true;
    }

    public static function global(string $flag): bool
    {
        return config('assinavelox.features.'.$flag, false) === true;
    }

    private static function planOf(?Organization $organization): ?Plan
    {
        return $organization?->currentSubscription()->with('plan')->first()?->plan;
    }
}
