<?php

namespace App\Services\Risk;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RiskReview;
use App\Models\User;
use App\Services\AdminLog\PlatformAction;
use App\Services\AdminLog\PlatformTrail;
use App\Services\Risk\Notifications\OrganizationRiskNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Aplica uma mudança de `organizations.risk_status` e a registra em `platform_audit_events`
 * (ação `risk.status_changed`) com regra(s), caso e origem — a explicação exigida pelo
 * roadmap §3.7. Nada aqui toca envelopes, aceites ou evidências.
 */
final class RiskTransitions
{
    /**
     * @param  list<string>  $ruleCodes
     * @param  array<string, int|string|null>  $extra
     */
    public function apply(
        Organization $organization,
        RiskStatus $to,
        string $origin,
        ?User $actor,
        ?RiskReview $review,
        array $ruleCodes = [],
        array $extra = [],
    ): bool {
        $from = RiskStatus::fromStored($organization->getAttribute('risk_status'));

        if ($from === $to) {
            return false;
        }

        $now = Carbon::now();

        Organization::withTrashed()->whereKey($organization->getKey())->update([
            'risk_status' => $to->value,
            'risk_status_changed_at' => $now,
        ]);

        $organization->setAttribute('risk_status', $to->value);
        $organization->setAttribute('risk_status_changed_at', $now);
        $organization->syncOriginalAttributes(['risk_status', 'risk_status_changed_at']);

        sort($ruleCodes);

        PlatformTrail::record(
            PlatformAction::RiskStatusChanged,
            $actor,
            PlatformTrail::TARGET_ORGANIZATION,
            (int) $organization->getKey(),
            (int) $organization->getKey(),
            array_merge([
                'from' => $from->value,
                'to' => $to->value,
                'origin' => $origin,
                'review' => $review?->ulid,
                'rules' => $ruleCodes,
            ], $extra),
        );

        return true;
    }

    public function notifyRestricted(Organization $organization): void
    {
        $this->notify($organization, new OrganizationRiskNotice($organization, OrganizationRiskNotice::KIND_RESTRICTED));
    }

    public function notifyDecision(Organization $organization, RiskDecision $decision): void
    {
        $this->notify($organization, new OrganizationRiskNotice($organization, OrganizationRiskNotice::KIND_DECISION, $decision));
    }

    /**
     * Proprietários e administradores ATIVOS da organização. Falha de entrega é registrada e
     * não desfaz a transição.
     */
    private function notify(Organization $organization, OrganizationRiskNotice $notice): void
    {
        try {
            $userIds = Membership::query()
                ->where('organization_id', $organization->getKey())
                ->where('status', MembershipStatus::Active->value)
                ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
                ->pluck('user_id');

            $users = User::query()->whereIn('id', $userIds)->get();

            if ($users->isNotEmpty()) {
                Notification::send($users, $notice);
            }
        } catch (Throwable $exception) {
            Log::warning('risk.notice_failed', [
                'organization_id' => $organization->getKey(),
                'kind' => $notice->kind,
                'exception' => $exception::class,
            ]);
        }
    }
}
