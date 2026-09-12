<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\CancelPendingPayment;
use App\Services\Billing\Exceptions\BillingActionException;
use App\Services\Billing\RequestRefund;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Ações do cliente sobre os próprios pagamentos (Fase 2, onda D; flag `extended_payments`,
 * desligada: 404).
 *
 * - **Cancelar pendente** (`billing.payments.cancel`): quem gere a cobrança (`manage_billing`).
 * - **Pedir estorno** (`billing.payments.refund`): só o proprietário (permissão `delete_organization`,
 *   verificada aqui — a Fase 2 autoriza por permissão, não por `org.role`), com senha
 *   confirmada, e só se a política da instalação permitir (`ASSINAVELOX_BILLING_REFUND_INITIATORS`
 *   contendo `owner`); apenas estorno integral e dentro da janela configurada.
 *
 * O pagamento chega pelo binding escopado da organização corrente: um pagamento de outra
 * organização simplesmente não resolve (404). Durante "acessar como", toda rota POST é bloqueada
 * pela lista fechada de `ReadOnlyRoutes` — a equipe de suporte nunca move dinheiro do cliente.
 */
class PaymentActionController extends Controller
{
    public function __construct(private readonly BillingSettings $settings) {}

    public function cancel(Request $request, Payment $payment, CancelPendingPayment $cancel): RedirectResponse
    {
        abort_unless($this->settings->extendedPayments(), 404);
        Gate::authorize('manageBilling', CurrentOrganization::instance()->get());

        try {
            $cancel->handle($payment);
        } catch (BillingActionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Pagamento pendente cancelado. Nada foi cobrado.');
    }

    public function refund(Request $request, Payment $payment, RequestRefund $refunds): RedirectResponse
    {
        abort_unless($this->settings->extendedPayments(), 404);
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);
        // Só o proprietário (`delete_organization`, a mesma permissão de `billing.cancel`).
        Gate::authorize('delete', $organization);
        abort_unless($this->settings->ownerCanRequestRefund(), 403, 'Pela política de estornos desta instalação, só a equipe da plataforma pode estornar pagamentos.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ], [], ['reason' => 'motivo', 'idempotency_key' => 'chave do pedido']);

        try {
            $refund = $refunds->handle(
                $payment,
                null,
                (string) $validated['reason'],
                $request->user(),
                PaymentRefund::INITIATOR_OWNER,
                (string) $validated['idempotency_key'],
            );
        } catch (BillingActionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        [$level, $message] = $refund->outcomeMessage();

        return back()->with($level, $message);
    }
}
