<?php

namespace App\Services\Sso;

use App\Enums\AuditEventType;
use App\Enums\MembershipStatus;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Membership;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Services\Organizations\SeatUsage;
use App\Services\Sso\Domains\SsoDomainManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Depois do protocolo: regra de negócio do login corporativo (docs/fase-3/sso.md §6).
 *
 * Autentica o USUÁRIO do painel — nunca o signatário de um envelope (T1).
 *
 *  - o e-mail precisa estar verificado pelo IdP E o domínio precisa ser um domínio VERIFICADO
 *    da organização da conexão;
 *  - vínculo pelo sujeito do IdP (`sso_identities`); sem vínculo, pelo e-mail — só com a conta
 *    local já confirmada, e nunca se ela já estiver ligada a OUTRO sujeito desta conexão;
 *  - sem conta: só com JIT ligado (cria o usuário já confirmado); sem membership: só com JIT
 *    (papel `member` por padrão, no máximo `admin`, NUNCA `owner`; respeita os assentos);
 *  - membership suspensa e conta bloqueada nunca entram; nada muda em OUTRA organização;
 *  - 2FA do usuário: continua valendo (padrão `keep` — o login termina no desafio do Fortify)
 *    ou é dispensado se a organização decidiu confiar no MFA do IdP (`trust_idp`, registrado).
 */
final class SsoLoginCompleter
{
    public function __construct(private readonly SsoConnectionManager $connections) {}

    /**
     * @throws SsoFailure
     */
    public function login(SsoConnection $connection, VerifiedIdentity $identity, Request $request): RedirectResponse
    {
        $resolved = $this->resolve($connection, $identity);
        $user = $resolved['user'];

        if ($user->hasTwoFactorEnabled() && ! $connection->trustsIdpForTwoFactor()) {
            // O desafio do Fortify termina o login; o listener de `Login` promove a sessão.
            $request->session()->put('login.id', $user->getKey());
            $request->session()->put('login.remember', false);
            $request->session()->put(SsoSession::PENDING_TWO_FACTOR, [
                'user_id' => (int) $user->getKey(),
                'connection_id' => (int) $connection->getKey(),
                'organization_id' => (int) $connection->organization_id,
                'domain' => $identity->emailDomain(),
                'provisioned' => $resolved['provisioned'],
                'linked' => $resolved['linked'],
                'expires_at' => Carbon::now()->getTimestamp() + 300,
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if ($user->hasTwoFactorEnabled()) {
            // `trust_idp` vale só para a organização DESTA conexão: em outra organização o código
            // do Fortify é pedido antes de liberar, e o break-glass não conta isto como 2FA
            // (SsoSession::needsTwoFactorStepUp — revisão adversarial da onda G).
            SsoSession::markTwoFactorTrusted($request, (int) $connection->organization_id);
        }

        $this->finalize($request, $user, $connection, $identity->emailDomain(), [
            'provisioned' => $resolved['provisioned'],
            'linked' => $resolved['linked'],
            'two_factor' => $user->hasTwoFactorEnabled() ? 'trusted_idp' : 'not_enrolled',
        ]);

        return redirect()->intended((string) config('fortify.home', '/dashboard'));
    }

    /**
     * Promoção depois do desafio 2FA do Fortify (listener de `Login`, SsoServiceProvider).
     *
     * @param  array<string, mixed>  $pending
     */
    public function afterTwoFactor(User $user, array $pending, Request $request): void
    {
        $connection = SsoConnection::withoutOrganizationScope()->find((int) ($pending['connection_id'] ?? 0));

        if ($connection === null || ! $connection->isActive() || (int) $connection->organization_id !== (int) ($pending['organization_id'] ?? 0)) {
            return;
        }

        $this->finalize($request, $user, $connection, (string) ($pending['domain'] ?? ''), [
            'provisioned' => (bool) ($pending['provisioned'] ?? false),
            'linked' => (bool) ($pending['linked'] ?? false),
            'two_factor' => 'verified',
        ]);
    }

    /**
     * Modo "Testar conexão": valida tudo, grava o resultado e NÃO entra nem vincula nada.
     */
    public function recordTest(SsoConnection $connection, VerifiedIdentity $identity, ?User $actor): void
    {
        try {
            $this->assertEmailAndDomain($connection, $identity);
        } catch (SsoFailure $failure) {
            $this->recordTestFailure($connection, $failure, $actor);

            return;
        }

        $this->connections->recordTest(
            $connection,
            true,
            'O provedor respondeu, a assinatura conferiu e o e-mail é de '.$identity->emailDomain().', um domínio verificado.',
            $actor,
        );
    }

    public function recordTestFailure(SsoConnection $connection, SsoFailure $failure, ?User $actor): void
    {
        $this->connections->recordTest($connection, false, 'Falhou: '.$failure->userMessage().' (código '.$failure->reason.')', $actor, $failure->reason);
    }

    public function loginFailed(?SsoConnection $connection, SsoFailure $failure): RedirectResponse
    {
        if ($connection !== null) {
            SsoTrail::record((int) $connection->organization_id, AuditEventType::SsoLoginFailed, SsoTrail::connectionPayload($connection, [
                'reason' => substr($failure->reason, 0, 80),
            ]));
        }

        return redirect()->route('login')->withErrors(['email' => $failure->publicMessage()]);
    }

    /**
     * @return array{user: User, membership: Membership, provisioned: bool, linked: bool}
     *
     * @throws SsoFailure
     */
    public function resolve(SsoConnection $connection, VerifiedIdentity $identity): array
    {
        $this->assertEmailAndDomain($connection, $identity);

        $organization = $connection->organization;
        $subjectHash = SsoIdentity::hashSubject((int) $connection->getKey(), $identity->subject);

        return DB::transaction(function () use ($connection, $identity, $organization, $subjectHash): array {
            $provisioned = false;
            $linked = false;

            $link = SsoIdentity::query()
                ->where('sso_connection_id', $connection->getKey())
                ->where('subject_hash', $subjectHash)
                ->first();

            $user = $link?->user;

            if ($user === null) {
                $user = User::query()->where('email', $identity->email)->first();
                $existing = null;

                if ($user !== null) {
                    if ($user->email_verified_at === null) {
                        throw new SsoFailure('local_account_unverified');
                    }

                    $existing = SsoIdentity::query()
                        ->where('sso_connection_id', $connection->getKey())
                        ->where('user_id', $user->getKey())
                        ->first();

                    // Ligada a OUTRO sujeito deste IdP: conflito. Ligada a um sujeito do IdP
                    // anterior (vínculo invalidado na troca de emissor/entityID): refaz o vínculo.
                    if ($existing !== null && ! $existing->isInvalidated()) {
                        throw new SsoFailure('identity_conflict');
                    }
                } else {
                    if (! $connection->jit_provisioning) {
                        throw new SsoFailure('no_account');
                    }

                    $user = $this->provisionUser($identity);
                    $provisioned = true;
                }

                try {
                    if ($existing !== null) {
                        $existing->forceFill(['subject_hash' => $subjectHash])->save();
                    } else {
                        SsoIdentity::query()->create([
                            'sso_connection_id' => $connection->getKey(),
                            'user_id' => $user->getKey(),
                            'subject_hash' => $subjectHash,
                        ]);
                    }
                } catch (UniqueConstraintViolationException) {
                    throw new SsoFailure('identity_conflict');
                }

                $linked = true;
            }

            if ($user->getAttribute('blocked_at') !== null) {
                throw new SsoFailure('account_blocked');
            }

            $membership = Membership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->first();

            if ($membership !== null && $membership->status !== MembershipStatus::Active) {
                throw new SsoFailure('membership_suspended');
            }

            if ($membership === null) {
                if (! $connection->jit_provisioning) {
                    throw new SsoFailure('not_a_member');
                }

                if (! SeatUsage::hasAvailable($organization)) {
                    throw new SsoFailure('no_seats');
                }

                $membership = Membership::query()->create([
                    'organization_id' => $organization->getKey(),
                    'user_id' => $user->getKey(),
                    // NUNCA owner (SsoConnection::jitRole()).
                    'role' => $connection->jitRole(),
                    'status' => MembershipStatus::Active,
                ]);
                $membership->forceFill(['auth_via' => 'sso'])->save();

                SsoTrail::record((int) $organization->getKey(), AuditEventType::SsoUserProvisioned, SsoTrail::connectionPayload($connection, [
                    'domain' => $identity->emailDomain(),
                    'role' => $connection->jitRole()->value,
                    'new_account' => $provisioned,
                ]), $user);
            }

            if ($linked) {
                SsoTrail::record((int) $organization->getKey(), AuditEventType::SsoIdentityLinked, SsoTrail::connectionPayload($connection, [
                    'domain' => $identity->emailDomain(),
                    'existing_account' => ! $provisioned,
                ]), $user);
            }

            return ['user' => $user, 'membership' => $membership, 'provisioned' => $provisioned, 'linked' => $linked];
        });
    }

    /**
     * @throws SsoFailure
     */
    private function assertEmailAndDomain(SsoConnection $connection, VerifiedIdentity $identity): void
    {
        if (! $identity->emailVerified) {
            throw new SsoFailure('email_not_verified');
        }

        $domain = $identity->emailDomain();

        if ($domain === '' || ! SsoDomainManager::isVerifiedFor((int) $connection->organization_id, $domain)) {
            throw new SsoFailure('domain_not_allowed');
        }
    }

    private function provisionUser(VerifiedIdentity $identity): User
    {
        $name = $identity->name ?? Str::before($identity->email, '@');

        $user = User::query()->create([
            'name' => mb_substr($name, 0, 120),
            'email' => $identity->email,
            // Senha aleatória que ninguém conhece: a conta entra pelo SSO (ou pela recuperação).
            'password' => Hash::make(Str::random(64)),
        ]);

        $user->forceFill(['email_verified_at' => Carbon::now()])->save();

        return $user;
    }

    /**
     * @param  array{provisioned: bool, linked: bool, two_factor: string}  $details
     */
    private function finalize(Request $request, User $user, SsoConnection $connection, string $domain, array $details): void
    {
        $organizationId = (int) $connection->organization_id;

        $request->session()->put(EnsureCurrentOrganization::SESSION_KEY, $organizationId);
        SsoSession::markAuthenticated($request, $organizationId, (int) $connection->getKey());

        $now = Carbon::now();

        $user->forceFill(['current_organization_id' => $organizationId])->save();

        Membership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->getKey())
            ->update(['auth_via' => 'sso', 'last_sso_login_at' => $now]);

        SsoIdentity::query()
            ->where('sso_connection_id', $connection->getKey())
            ->where('user_id', $user->getKey())
            ->update(['last_login_at' => $now]);

        $connection->forceFill(['last_login_at' => $now])->saveQuietly();

        SsoTrail::record($organizationId, AuditEventType::SsoLoginSucceeded, SsoTrail::connectionPayload($connection, [
            'domain' => $domain,
            'provisioned' => $details['provisioned'],
            'linked' => $details['linked'],
            'two_factor' => $details['two_factor'],
        ]), $user);
    }
}
