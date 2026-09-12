<?php

namespace App\Services\Api;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Membership;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Gate;

/**
 * Documentação OpenAPI da API v1 (Scramble, `/docs/api` e `/docs/api.json`).
 *
 * - Só `/api/v1` é documentado (`config/scramble.php` → `api_path`).
 * - Esquema de autenticação: `Authorization: Bearer <token>`.
 * - Acesso (interface E JSON) pelo gate `viewApiDocs`: usuário autenticado, com membership
 *   ATIVA na organização corrente da sessão, `manage_integrations` e a flag
 *   `api_integrations` ligada. Convidados e demais usuários recebem 403 — inclusive em
 *   `local` (App\Http\Middleware\ApiDocsAccess não tem o atalho do ambiente local).
 */
final class ApiDocumentation
{
    public static function configure(): void
    {
        Gate::define('viewApiDocs', static fn (?User $user = null): bool => $user instanceof User && self::userCanView($user));

        Scramble::afterOpenApiGenerated(static function (OpenApi $openApi): void {
            $openApi->secure(
                SecurityScheme::http('bearer')
                    ->as('bearerAuth')
                    ->setDescription('Token de API criado em Integrações → Chaves, enviado como "Authorization: Bearer {token}". O texto do token é exibido uma única vez, na criação.'),
            );
        });
    }

    public static function userCanView(User $user): bool
    {
        $request = request();
        $sessionOrganization = $request->hasSession() ? $request->session()->get(EnsureCurrentOrganization::SESSION_KEY) : null;
        $organizationId = $sessionOrganization ?? $user->current_organization_id;

        if (! is_numeric($organizationId)) {
            return false;
        }

        $membership = Membership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('organization_id', (int) $organizationId)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization')
            ->first();

        return $membership !== null
            && $membership->hasPermission(Permission::ManageIntegrations)
            && ApiFeature::enabled($membership->organization);
    }
}
