<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\Exceptions\CheckoutException;
use App\Services\Billing\StartCheckout;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checkout Pro (ROUTES §1.2 `billing.checkout` / `billing.return`).
 *
 * ## `store`: abrir o checkout
 *
 * Cria (ou reaproveita) um `Payment` local `pending` com o nosso `external_reference`,
 * pede a preferência ao gateway e devolve `Inertia::location($initPoint)` — o
 * redirecionamento externo obrigatório numa visita Inertia. Sempre o `init_point`, nunca
 * o `sandbox_init_point` (a própria documentação desaconselha usá-lo, inclusive em teste).
 *
 * ## `return`: o retorno do Checkout Pro **é apenas informativo**
 *
 * Esta rota **nunca** ativa plano, nunca muda o estado de um pagamento e nunca lê nada
 * da query string do provedor para tomar decisão. Motivo: a `back_url` é um GET disparado
 * pelo **navegador do comprador**, com parâmetros forjáveis, que pode nunca acontecer (a
 * aba pode ser fechada) e que, para meios offline como boleto e Pix, chega sempre como
 * `pending` mesmo quando o pagamento é aprovado depois.
 *
 * Quem confirma pagamento é o webhook autenticado seguido de `GET /v1/payments/{id}`
 * (ver `App\Http\Controllers\Webhooks\MercadoPagoController` e
 * `App\Services\Billing\SyncPaymentFromGateway`). Aqui a página só diz "estamos
 * confirmando seu pagamento".
 *
 * O `outcome` chega pela própria rota (`success|failure|pending`), então nem os
 * parâmetros do provedor precisam ser lidos.
 */
class BillingCheckoutController extends Controller
{
    public function __construct(private readonly StartCheckout $checkout) {}

    public function store(Request $request): Response|RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_active', true), Rule::notIn([Plan::CODE_FREE])],
            'interval' => ['required', Rule::in(['monthly', 'yearly'])],
        ], [], ['plan' => 'plano', 'interval' => 'periodicidade']);

        $plan = Plan::query()->where('code', $validated['plan'])->firstOrFail();

        try {
            $payment = $this->checkout->handle($organization, $plan, $request->user());
        } catch (CheckoutException $exception) {
            return back()->with('checkout_error', $exception->getMessage());
        }

        // Redirecionamento para fora da aplicação numa visita Inertia.
        return Inertia::location((string) $payment->checkout_url);
    }

    /**
     * Retorno do Checkout Pro. Informativo, e só.
     */
    public function return(Request $request, string $outcome): RedirectResponse
    {
        abort_unless(in_array($outcome, ['success', 'failure', 'pending'], true), 404);

        // Nada é lido de `payment_id`, `status` ou `collection_status`: esses valores vêm
        // da barra de endereços do comprador e não têm autoridade nenhuma sobre o plano.
        return redirect()
            ->route('billing.index')
            ->with('checkout_return', $outcome);
    }
}
