<?php

namespace App\Services\Api;

use App\Enums\ApiAbility;
use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\OrganizationSettings;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Criação, listagem e revogação dos tokens da API v1 (docs/fase-2/api-v1.md §2).
 *
 * Regras:
 *  - só quem tem `manage_integrations` (membership ATIVA) cria ou revoga, e só com a flag
 *    `api_integrations` ligada para a organização;
 *  - o token pertence à organização E a quem o criou (`tokenable` = criador);
 *  - abilities explícitas, do conjunto fechado App\Enums\ApiAbility — nunca `*`;
 *  - anti-escalada: ninguém concede uma ability cujas permissões não tem
 *    ({@see ApiAbility::requiredPermissions()});
 *  - o texto do token é devolvido UMA vez ({@see NewApiToken}); o banco guarda o SHA-256
 *    (padrão do Sanctum) e um prefixo curto de exibição.
 *
 * Contrato para as telas (Integrações → Chaves), fora desta área: chamar `issue()` num POST
 * com App\Http\Requests\Api\StoreApiTokenRequest e mostrar `plainTextToken` uma única vez;
 * `revoke()` num DELETE; listar com `forOrganization()` + App\Http\Resources\Api\ApiTokenResource.
 */
final class ApiTokenManager
{
    public const NAME_MAX = 120;

    /**
     * @param  list<string|ApiAbility>  $abilities
     *
     * @throws AuthorizationException|ValidationException
     */
    public function issue(Membership $actor, string $name, array $abilities, ?CarbonInterface $expiresAt = null): NewApiToken
    {
        $this->authorizeManager($actor);

        /** @var Organization $organization */
        $organization = $actor->organization;

        if (! ApiFeature::enabled($organization)) {
            throw new AuthorizationException('A API não está disponível para esta organização.');
        }

        // Mesma barreira do `org.2fa` (e da autenticação da API): sem TOTP numa organização que
        // exige 2FA, a pessoa não emite uma chave que depois não autenticaria.
        if (OrganizationSettings::of($organization)->requireTwoFactor() && ! $actor->user->hasTwoFactorEnabled()) {
            throw new AuthorizationException('Esta organização exige autenticação em duas etapas. Ative o 2FA antes de criar chaves de API.');
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw ValidationException::withMessages(['name' => 'Informe um nome de até '.self::NAME_MAX.' caracteres.']);
        }

        $selected = self::normalize($abilities);

        if ($selected === []) {
            throw ValidationException::withMessages(['abilities' => 'Escolha pelo menos uma permissão para a chave.']);
        }

        $beyond = self::beyond($actor, $selected);

        if ($beyond !== []) {
            throw ValidationException::withMessages([
                'abilities' => 'Você não pode conceder a esta chave o que não pode fazer: '
                    .implode(', ', array_map(static fn (ApiAbility $ability): string => $ability->value, $beyond)).'.',
            ]);
        }

        if ($expiresAt !== null) {
            $max = Carbon::now()->addDays(self::maxExpirationDays());

            if (! $expiresAt->isFuture() || $expiresAt->greaterThan($max)) {
                throw ValidationException::withMessages([
                    'expires_at' => 'A validade precisa estar no futuro e em até '.self::maxExpirationDays().' dias.',
                ]);
            }
        }

        $limit = self::maxActivePerOrganization();

        if (self::forOrganization($organization)->usable()->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => "Esta organização já tem {$limit} chaves ativas. Revogue uma antes de criar outra.",
            ]);
        }

        $secret = (string) config('sanctum.token_prefix', '').Str::random(40);
        $secret .= hash('crc32b', $secret);

        $user = $actor->user;

        $token = DB::transaction(function () use ($actor, $organization, $name, $selected, $expiresAt, $secret, $user): ApiToken {
            $token = new ApiToken;
            $token->forceFill([
                'tokenable_type' => $user->getMorphClass(),
                'tokenable_id' => $actor->user_id,
                'organization_id' => $organization->getKey(),
                'created_by_user_id' => $actor->user_id,
                'name' => $name,
                'token' => hash('sha256', $secret),
                'abilities' => array_map(static fn (ApiAbility $ability): string => $ability->value, $selected),
                'expires_at' => $expiresAt,
                'token_prefix' => mb_substr($secret, 0, 8),
            ])->save();

            ApiTokenTrail::record($token, AuditEventType::ApiTokenCreated, [
                'name' => $name,
                'abilities' => $token->abilityValues(),
                'expires_at' => ApiFormat::date($token->expires_at),
            ]);

            return $token;
        });

        return new NewApiToken($token, $token->getKey().'|'.$secret);
    }

    /**
     * Revoga (não apaga: o registro continua na aba de chaves e nos logs). Idempotente:
     * revogar de novo devolve false.
     *
     * @throws AuthorizationException
     */
    public function revoke(ApiToken $token, Membership $actor): bool
    {
        $this->authorizeManager($actor);

        if ($token->organization_id !== $actor->organization_id) {
            throw new AuthorizationException('Esta chave não pertence à organização.');
        }

        if ($token->isRevoked()) {
            return false;
        }

        DB::transaction(function () use ($token, $actor): void {
            $token->forceFill([
                'revoked_at' => Carbon::now(),
                'revoked_by_user_id' => $actor->user_id,
            ])->save();

            ApiTokenTrail::record($token, AuditEventType::ApiTokenRevoked, ['name' => $token->name]);
        });

        return true;
    }

    /**
     * Tokens da organização, mais recentes primeiro (sem depender da organização corrente).
     *
     * @return Builder<ApiToken>
     */
    public static function forOrganization(Organization $organization): Builder
    {
        return ApiToken::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('id');
    }

    /**
     * Abilities que esta pessoa pode conceder (para a tela de criação).
     *
     * @return list<ApiAbility>
     */
    public static function grantable(Membership $actor): array
    {
        return array_values(array_filter(
            ApiAbility::cases(),
            static fn (ApiAbility $ability): bool => Permissions::covers($actor, $ability->requiredPermissions()),
        ));
    }

    /**
     * @param  list<ApiAbility>  $abilities
     * @return list<ApiAbility> as que exigem permissões que a pessoa NÃO tem
     */
    public static function beyond(Membership $actor, array $abilities): array
    {
        return array_values(array_filter(
            $abilities,
            static fn (ApiAbility $ability): bool => ! Permissions::covers($actor, $ability->requiredPermissions()),
        ));
    }

    /**
     * @param  list<string|ApiAbility>  $abilities
     * @return list<ApiAbility>
     *
     * @throws ValidationException
     */
    public static function normalize(array $abilities): array
    {
        $selected = [];

        foreach ($abilities as $value) {
            $ability = $value instanceof ApiAbility ? $value : ApiAbility::tryFrom((string) $value);

            if ($ability === null) {
                throw ValidationException::withMessages(['abilities' => 'Permissão de chave desconhecida.']);
            }

            $selected[$ability->value] = $ability;
        }

        // Ordem estável (a do catálogo), sem repetição.
        return array_values(array_filter(
            ApiAbility::cases(),
            static fn (ApiAbility $ability): bool => isset($selected[$ability->value]),
        ));
    }

    public static function maxExpirationDays(): int
    {
        return max(1, (int) config('assinavelox.api.tokens.max_expiration_days', 365));
    }

    public static function maxActivePerOrganization(): int
    {
        return max(1, (int) config('assinavelox.api.tokens.max_active_per_organization', 50));
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeManager(Membership $actor): void
    {
        if (! $actor->isActive() || ! $actor->hasPermission(Permission::ManageIntegrations)) {
            throw new AuthorizationException('Você não tem permissão para gerenciar chaves de API.');
        }
    }
}
