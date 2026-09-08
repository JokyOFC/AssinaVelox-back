<?php

namespace App\Services\Organizations;

use App\Enums\MembershipStatus;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Plan;

/**
 * Assentos do plano: memberships ativas + convites pendentes contam contra
 * `plans.user_quota` (null = ilimitado).
 *
 * Há UMA definição de "assento ocupado" para toda a aplicação — `used` já inclui os convites
 * pendentes, de modo que `used + available == limit` em qualquer estado. É a leitura da tela
 * de Usuários em DESIGN_SYSTEM §2 ("6 de 10 assentos do plano Profissional em uso · 1 convite
 * pendente" com rodapé "4 assentos disponíveis": 6 + 4 = 10, com um convite pendente entre as
 * linhas) e a mesma conta que `unavailableMessage()` já usava.
 */
class SeatUsage
{
    /**
     * @return array{used: int, active_memberships: int, limit: int|null, pending_invitations: int, available: int|null, plan_name: string}
     */
    public static function for(Organization $organization): array
    {
        $subscription = $organization->currentSubscription()->with('plan')->first();
        /** @var Plan|null $plan */
        $plan = $subscription?->plan;

        $used = $organization->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->count();

        $pending = MembershipInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        $limit = $plan?->user_quota;
        $taken = $used + $pending;

        return [
            'used' => $taken,
            'active_memberships' => $used,
            'limit' => $limit,
            'pending_invitations' => $pending,
            'available' => $limit === null ? null : max(0, $limit - $taken),
            'plan_name' => $plan->name ?? 'Grátis',
        ];
    }

    public static function hasAvailable(Organization $organization, int $quantity = 1): bool
    {
        $seats = self::for($organization);

        return $seats['available'] === null || $seats['available'] >= $quantity;
    }

    /**
     * Um convite só ocupa assento enquanto está pendente (não aceito, não revogado,
     * não expirado). Reenviar um convite pendente não consome assento novo; reenviar
     * um expirado volta a ocupar um assento e por isso precisa de disponibilidade.
     */
    public static function occupiesSeat(MembershipInvitation $invitation): bool
    {
        return $invitation->accepted_at === null
            && $invitation->revoked_at === null
            && $invitation->expires_at->isFuture();
    }

    /**
     * Assentos exigidos para (re)colocar o convite em circulação.
     */
    public static function hasAvailableForResend(Organization $organization, MembershipInvitation $invitation): bool
    {
        if (self::occupiesSeat($invitation)) {
            return true;
        }

        return self::hasAvailable($organization, 1);
    }

    /**
     * Mensagem única (PT-BR) para todo bloqueio por falta de assentos.
     */
    public static function unavailableMessage(Organization $organization): string
    {
        $seats = self::for($organization);

        if ($seats['limit'] === null) {
            return 'Sem assentos disponíveis no plano '.$seats['plan_name'].'.';
        }

        return 'Sem assentos disponíveis no plano '.$seats['plan_name'].' ('.$seats['used'].' de '.$seats['limit'].' em uso, contando convites pendentes). Faça upgrade do plano ou remova um usuário antes de continuar.';
    }
}
