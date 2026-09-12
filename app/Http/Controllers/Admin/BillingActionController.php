<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Billing\ReconcilePaymentsJob;
use App\Jobs\Billing\SyncMercadoPagoPayment;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\ReconciliationItem;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\CancelPendingPayment;
use App\Services\Billing\Exceptions\BillingActionException;
use App\Services\Billing\RefreshPaymentMethods;
use App\Services\Billing\RequestRefund;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Operações financeiras da equipe da plataforma (Fase 2, onda D). Todas atrás de `platform-admin`
 * e da flag `extended_payments` (desligada: 404). Estorno e cancelamento exigem senha confirmada
 * (`password.confirm` na rota). O pagamento é localizado pelo ULID fora do escopo de organização —
 * o painel interno não faz binding de models escopados.
 *
 * Nenhuma destas ações toca documentos. A trilha é a própria linha do estorno (quem pediu, motivo,
 * quando, chave de idempotência, status do provedor) mais o log estruturado.
 */
class BillingActionController extends Controller
{
    public function __construct(private readonly BillingSettings $settings) {}

    public function refund(Request $request, string $payment, RequestRefund $refunds): RedirectResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ], [], ['amount_cents' => 'valor', 'reason' => 'motivo', 'idempotency_key' => 'chave do pedido']);

        try {
            $refund = $refunds->handle(
                $this->payment($payment),
                isset($validated['amount_cents']) ? (int) $validated['amount_cents'] : null,
                (string) $validated['reason'],
                $request->user(),
                PaymentRefund::INITIATOR_PLATFORM_ADMIN,
                (string) $validated['idempotency_key'],
            );
        } catch (BillingActionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        [$level, $message] = $refund->outcomeMessage();

        return back()->with($level, $message);
    }

    public function cancel(Request $request, string $payment, CancelPendingPayment $cancel): RedirectResponse
    {
        $this->ensureEnabled();

        try {
            $cancel->handle($this->payment($payment));
        } catch (BillingActionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Log::info('billing.admin.cancelled', ['payment' => $payment, 'actor' => $request->user()?->getKey()]);

        return back()->with('success', 'Pagamento pendente cancelado. Nada foi cobrado.');
    }

    public function resync(Request $request, string $payment): RedirectResponse
    {
        $this->ensureEnabled();

        $model = $this->payment($payment);

        if ($model->provider_payment_id === null) {
            return back()->with('info', 'Este pagamento ainda não existe no Mercado Pago (o checkout foi aberto, mas nada foi pago).');
        }

        // Exatamente o caminho do webhook: GET /v1/payments/{id} é a fonte da verdade.
        SyncMercadoPagoPayment::dispatch((string) $model->provider_payment_id);

        return back()->with('success', 'Consulta ao Mercado Pago enviada. O status é atualizado a partir da resposta do provedor.');
    }

    public function reconcile(Request $request): RedirectResponse
    {
        $this->ensureEnabled();

        ReconcilePaymentsJob::dispatch('admin', (int) $request->user()->getKey());

        return back()->with('success', 'Conciliação iniciada. As divergências aparecem na aba "Conciliação" — nenhum pagamento é alterado por ela.');
    }

    public function refreshMethods(Request $request, RefreshPaymentMethods $refresh): RedirectResponse
    {
        $this->ensureEnabled();

        $check = $refresh->handle($request->user());

        return $check->status === 'ok'
            ? back()->with('success', 'Meios de pagamento da conta consultados no Mercado Pago.')
            : back()->with('error', 'Não foi possível consultar os meios de pagamento no Mercado Pago. A configuração anterior continua valendo.');
    }

    public function resolveDivergence(Request $request, int $item): RedirectResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['note' => 'observação']);

        $divergence = ReconciliationItem::query()->findOrFail($item);

        if ($divergence->resolved_at === null) {
            // Marcar como revisada NÃO muda o pagamento: é só a anotação da revisão humana.
            $divergence->forceFill([
                'resolved_at' => Carbon::now(),
                'resolved_by_user_id' => $request->user()->getKey(),
                'resolution_note' => trim((string) $validated['note']),
            ])->save();
        }

        return back()->with('success', 'Divergência marcada como revisada.');
    }

    private function ensureEnabled(): void
    {
        abort_unless($this->settings->extendedPayments(), 404);
    }

    private function payment(string $ulid): Payment
    {
        return Payment::withoutOrganizationScope()->where('ulid', $ulid)->firstOrFail();
    }
}
