<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve a organização corrente do usuário autenticado (alias `org`).
 *
 * 1. `session('current_organization_id')` → membership ATIVA nessa organização;
 * 2. fallback: `users.current_organization_id` e, por fim, a primeira membership ativa;
 * 3. sem membership ativa → redireciona para `organizations.create`.
 *
 * Define App\Support\CurrentOrganization (organização + membership) para o escopo global,
 * policies e props compartilhadas, e sincroniza `users.current_organization_id`.
 */
class EnsureCurrentOrganization
{
    public const SESSION_KEY = 'current_organization_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $membership = $this->resolveMembership($request, $user);

        if ($membership === null) {
            if ($request->expectsJson()) {
                abort(403, 'Você não participa de nenhuma organização.');
            }

            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('organizations.create');
        }

        $organization = $membership->organization;

        CurrentOrganization::instance()->set($organization, $membership);

        $request->session()->put(self::SESSION_KEY, $organization->getKey());

        if ($user->current_organization_id !== $organization->getKey()) {
            $user->forceFill(['current_organization_id' => $organization->getKey()])->saveQuietly();
        }

        return $next($request);
    }

    protected function resolveMembership(Request $request, User $user): ?Membership
    {
        $candidates = array_values(array_unique(array_filter([
            $request->session()->get(self::SESSION_KEY),
            $user->current_organization_id,
        ])));

        foreach ($candidates as $organizationId) {
            $membership = $this->activeMembership($user, (int) $organizationId);

            if ($membership !== null) {
                return $membership;
            }
        }

        return Membership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization')
            ->orderBy('id')
            ->first();
    }

    protected function activeMembership(User $user, int $organizationId): ?Membership
    {
        return Membership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization')
            ->first();
    }
}
