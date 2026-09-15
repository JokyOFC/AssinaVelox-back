<?php

namespace App\Services\Sso;

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\Sso\Notifications\SsoBreakGlassNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Login corporativo obrigatório com "break-glass" (docs/fase-3/sso.md §5).
 *
 * Com a conexão ATIVA e `enforce` ligado (e a flag do protocolo valendo), a organização só é
 * acessível a quem entrou NESTA sessão pelo SSO dela. Owners mantêm o acesso de emergência por
 * senha + 2FA: cada uso gera evento `sso.break_glass_used` (tom de alerta) e aviso aos demais
 * owners e admins. Owner sem 2FA é mandado ativar o 2FA — senha sozinha nunca basta.
 *
 * Desativar a conexão, desligar a obrigatoriedade ou desligar a flag suspende a exigência na
 * hora: a organização nunca fica trancada.
 */
final class SsoEnforcement
{
    public const ALLOW = 'allow';

    public const REQUIRE_SSO = 'require_sso';

    public const OWNER_NEEDS_TWO_FACTOR = 'owner_needs_two_factor';

    /** O 2FA da conta existe, mas não foi digitado nesta sessão (veio do `trust_idp` de outra organização). */
    public const TWO_FACTOR_STEP_UP = 'two_factor_step_up';

    public function enforcingConnection(Organization $organization): ?SsoConnection
    {
        if (! SsoFeature::anyFor($organization)) {
            return null;
        }

        $connection = SsoConnection::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->first();

        if ($connection === null || ! $connection->enforcesSso() || ! SsoFeature::enabledFor($connection->protocol, $organization)) {
            return null;
        }

        return $connection;
    }

    public function evaluate(Request $request, User $user, Organization $organization, ?Membership $membership): string
    {
        $connection = $this->enforcingConnection($organization);

        if ($connection === null) {
            return self::ALLOW;
        }

        if (SsoSession::authenticatedVia($request, (int) $organization->getKey()) === (int) $connection->getKey()) {
            return self::ALLOW;
        }

        if ($membership !== null && $membership->role === MembershipRole::Owner) {
            if (! $user->hasTwoFactorEnabled()) {
                return self::OWNER_NEEDS_TWO_FACTOR;
            }

            // Break-glass = senha + 2FA DIGITADO nesta sessão. O 2FA dispensado pelo IdP de outra
            // organização (`trust_idp`) não conta: o código é pedido antes (revisão G).
            if (! SsoSession::twoFactorTyped($request, $user)) {
                return self::TWO_FACTOR_STEP_UP;
            }

            $this->recordBreakGlass($request, $user, $organization, $connection);

            return self::ALLOW;
        }

        return self::REQUIRE_SSO;
    }

    private function recordBreakGlass(Request $request, User $user, Organization $organization, SsoConnection $connection): void
    {
        $organizationId = (int) $organization->getKey();

        if (! $request->hasSession() || SsoSession::breakGlassRecorded($request, $organizationId)) {
            return;
        }

        SsoSession::recordBreakGlass($request, $organizationId);

        SsoTrail::record($organizationId, AuditEventType::SsoBreakGlassUsed, SsoTrail::connectionPayload($connection, [
            'two_factor' => true,
        ]), $user);

        Log::warning('sso.break_glass_used', ['organization' => $organization->ulid, 'connection' => $connection->ulid, 'user_id' => $user->getKey()]);

        try {
            $recipients = User::query()
                ->whereIn('id', Membership::query()
                    ->where('organization_id', $organizationId)
                    ->where('status', MembershipStatus::Active->value)
                    ->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value])
                    ->where('user_id', '!=', $user->getKey())
                    ->select('user_id'))
                ->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new SsoBreakGlassNotification($organizationId, $organization->name, $user->name));
            }
        } catch (Throwable $exception) {
            Log::warning('sso.break_glass_notification_failed', ['error' => $exception::class]);
        }
    }
}
