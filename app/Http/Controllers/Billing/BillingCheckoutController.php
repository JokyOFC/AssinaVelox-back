<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Checkout Pro (ROUTES §1.2 billing.checkout / billing.return).
 * // TODO(Wave B - cobrança): Payment(pending) + preferência via PaymentGateway → Inertia::location(init_point).
 */
class BillingCheckoutController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'code')->where('is_active', true), Rule::notIn([Plan::CODE_FREE])],
            'interval' => ['required', Rule::in(['monthly', 'yearly'])],
        ], [], ['plan' => 'plano', 'interval' => 'periodicidade']);

        return redirect()->route('billing.index')
            ->with('info', 'O checkout com Mercado Pago estará disponível em breve.');
    }

    public function return(Request $request, string $outcome): RedirectResponse
    {
        abort_unless(in_array($outcome, ['success', 'failure', 'pending'], true), 404);

        // O estado final vem sempre do webhook; aqui só informamos o retorno do Checkout Pro.
        return redirect()->route('billing.index')->with(match ($outcome) {
            'success' => 'success',
            'pending' => 'info',
            default => 'error',
        }, match ($outcome) {
            'success' => 'Pagamento aprovado! Seu plano será atualizado assim que confirmarmos com o Mercado Pago.',
            'pending' => 'Pagamento em análise. Avisaremos quando for confirmado.',
            default => 'Pagamento não aprovado.',
        });
    }
}
