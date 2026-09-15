<?php

namespace App\Services\Sso;

use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leva o navegador ao provedor de identidade (OIDC: endpoint de autorização; SAML:
 * HTTP-Redirect com o AuthnRequest). `Inertia::location` faz a navegação de topo tanto para
 * requisição Inertia (409 + X-Inertia-Location) quanto para formulário comum (302).
 */
final class SsoRedirector
{
    public function __construct(
        private readonly OidcLoginFlow $oidc,
        private readonly SamlLoginFlow $saml,
    ) {}

    /**
     * @throws SsoFailure
     */
    public function redirect(SsoConnection $connection, Request $request, string $mode, ?User $initiator = null, ?string $loginHint = null): Response
    {
        if ($connection->isOidc()) {
            return Inertia::location($this->oidc->start($connection, $request, $mode, $initiator, $loginHint));
        }

        $started = $this->saml->start($connection, $request, $mode, $initiator);
        $response = Inertia::location($started['url']);
        $response->headers->setCookie($started['cookie']);

        return $response;
    }
}
