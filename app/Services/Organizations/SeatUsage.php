<?php

namespace App\Services\Organizations;

use App\Enums\MembershipStatus;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Plan;

/**
 * Assentos do plano: memberships ativas + convites pendentes contam contra
 * `plans.user_quota` (null = ilimitado).
 */
class SeatUsage
{
    /**
     * @return array{used: int, limit: int|null, pending_invitations: int, available: int|null, plan_name: string}
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

        return [
            'used' => $used,
            'limit' => $limit,
            'pending_invitations' => $pending,
            'available' => $limit === null ? null : max(0, $limit - $used - $pending),
            'plan_name' => $plan->name ?? 'Grátis',
        ];
    }

    public static function hasAvailable(Organization $organization, int $quantity = 1): bool
    {
        $seats = self::for($organization);

        return $seats['available'] === null || $seats['available'] >= $quantity;
    }
}
