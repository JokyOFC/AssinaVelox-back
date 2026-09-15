<?php

namespace App\Http\Controllers\Sso;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Membership;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\Sso\Domains\SsoDomainManager;
use App\Services\Sso\OidcLoginFlow;
use App\Services\Sso\SsoEnforcement;
use App\Services\Sso\SsoFailure;
use App\Services\Sso\SsoFeature;
use App\Services\Sso\SsoLoginCompleter;
use App\Services\Sso\SsoRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrada pelo login corporativo (docs/fase-3/sso.md §6 e §5):
 *
 *  - `sso.login.start` (POST, tela de login): o DOMÍNIO do e-mail leva à organização dona do
 *    domínio verificado e dela à conexão ativa;
 *  - `sso.required` / `sso.required.start`: organização com SSO obrigatório — quem entrou por
 *    senha é trazido aqui e segue para o provedor da organização.
 *
 * Autentica o USUÁRIO do painel, nunca o signatário (T1).
 */
class SsoLoginController extends Controller
{
    public function __construct(
        private readonly SsoRedirector $redirector,
        private readonly SsoLoginCompleter $completer,
        private readonly SsoEnforcement $enforcement,
    ) {}

    public function start(Request $request): Response
    {
        abort_unless(SsoFeature::anyGlobal(), 404);

        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']], [], ['email' => 'e-mail']);
        $email = mb_strtolower(trim((string) $data['email']));
        $domain = substr($email, (int) strrpos($email, '@') + 1);

        $owner = SsoDomainManager::verifiedOwner($domain);
        $connection = $owner === null ? null : SsoConnection::withoutOrganizationScope()
            ->where('organization_id', $owner->organization_id)
            ->first();

        if ($connection === null) {
            throw ValidationException::withMessages(['email' => (new SsoFailure('no_connection'))->userMessage()]);
        }

        if (! $connection->isActive() || ! SsoFeature::enabledFor($connection->protocol, $connection->organization)) {
            throw ValidationException::withMessages(['email' => (new SsoFailure('connection_unavailable'))->userMessage()]);
        }

        try {
            return $this->redirector->redirect($connection, $request, OidcLoginFlow::MODE_LOGIN, null, $email);
        } catch (SsoFailure $failure) {
            $this->completer->loginFailed($connection, $failure);

            throw ValidationException::withMessages(['email' => $failure->publicMessage()]);
        }
    }

    public function required(Request $request): Response|RedirectResponse
    {
        abort_unless(SsoFeature::anyGlobal(), 404);

        [$membership, $connection] = $this->enforcedContext($request);

        if ($membership === null || $connection === null) {
            return redirect((string) config('fortify.home', '/dashboard'));
        }

        return Inertia::render('auth/sso-required', [
            'organization' => ['name' => $membership->organization->name],
            'connection' => ['protocol_label' => $connection->protocol->label()],
        ])->toResponse($request);
    }

    public function requiredStart(Request $request): Response
    {
        abort_unless(SsoFeature::anyGlobal(), 404);

        [$membership, $connection] = $this->enforcedContext($request);

        abort_if($membership === null || $connection === null, 404);

        /** @var User $user */
        $user = $request->user();

        try {
            return $this->redirector->redirect($connection, $request, OidcLoginFlow::MODE_LOGIN, null, $user->email);
        } catch (SsoFailure $failure) {
            $this->completer->loginFailed($connection, $failure);

            return back()->withErrors(['sso' => $failure->publicMessage()]);
        }
    }

    /**
     * Organização corrente da sessão (a mesma que o middleware `org` escolheria) com SSO
     * obrigatório ainda não cumprido nesta sessão.
     *
     * @return array{0: Membership|null, 1: SsoConnection|null}
     */
    private function enforcedContext(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $organizationId = $request->session()->get(EnsureCurrentOrganization::SESSION_KEY) ?? $user->current_organization_id;

        if ($organizationId === null) {
            return [null, null];
        }

        $membership = Membership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('organization_id', (int) $organizationId)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization')
            ->first();

        if ($membership === null) {
            return [null, null];
        }

        if ($this->enforcement->evaluate($request, $user, $membership->organization, $membership) !== SsoEnforcement::REQUIRE_SSO) {
            return [null, null];
        }

        return [$membership, $this->enforcement->enforcingConnection($membership->organization)];
    }
}
