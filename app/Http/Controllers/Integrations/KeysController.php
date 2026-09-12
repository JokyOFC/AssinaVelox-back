<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IntegrationController;
use App\Http\Requests\Api\StoreApiTokenRequest;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Organization;
use App\Services\Api\ApiFeature;
use App\Services\Api\ApiTokenManager;
use App\Services\RestHooks\IntegrationsNavigation;
use App\Services\RestHooks\RestHookSubscriptions;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integrações → Chaves: criar e revogar tokens da API v1 (contrato em docs/fase-2/api-v1.md
 * §14). A regra de negócio (anti-escalada, limite de chaves, validade, trilha) é do
 * App\Services\Api\ApiTokenManager; aqui só a tela.
 *
 * "Exibido uma única vez": a criação responde DIRETO com a página de chaves (resposta Inertia
 * ao POST), com o texto do token numa prop — ele não passa pela sessão (sem flash), não vai
 * para log e não volta em nenhuma outra requisição. A página copia o valor para o estado
 * local e o apaga do histórico do navegador (`router.replaceProp`).
 */
class KeysController extends Controller
{
    public function __construct(
        private readonly ApiTokenManager $tokens,
        private readonly RestHookSubscriptions $subscriptions,
    ) {}

    public function store(Request $request): Response
    {
        [$organization, $membership] = $this->context();

        // Resolvida só depois da flag: com ela desligada é 404 antes de qualquer validação.
        /** @var StoreApiTokenRequest $form */
        $form = app(StoreApiTokenRequest::class);

        $issued = $this->tokens->issue($membership, (string) $form->validated('name'), $form->abilities(), $form->expiresAt());

        $response = Inertia::render('integrations/keys', IntegrationController::keysProps($organization, $membership, [
            'id' => $issued->token->ulid,
            'name' => $issued->token->name,
            'token' => $issued->plainTextToken,
        ]))->toResponse($request);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function destroy(ApiToken $apiToken): RedirectResponse
    {
        [, $membership] = $this->context();

        $revoked = $this->tokens->revoke($apiToken, $membership);
        $removed = $this->subscriptions->removeForToken($apiToken);

        $message = $revoked ? 'Chave revogada. Chamadas com ela passam a receber 401 imediatamente.' : 'Esta chave já estava revogada.';

        if ($removed > 0) {
            $message .= $removed === 1
                ? ' A assinatura de webhook criada por ela foi removida.'
                : " As {$removed} assinaturas de webhook criadas por ela foram removidas.";
        }

        return redirect()->route('integrations.keys')->with('success', $message);
    }

    /**
     * @return array{0: Organization, 1: Membership}
     */
    private function context(): array
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $membership = $current->membership();

        abort_unless($organization !== null && ApiFeature::enabled($organization), 404);
        abort_unless(IntegrationsNavigation::canManage($membership), 403, 'Só quem pode gerenciar a API e as integrações cria ou revoga chaves.');

        /** @var Membership $membership */
        return [$organization, $membership];
    }
}
