<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\PaymentReceipt;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recibo interno em PDF (ROUTES §1.2 `billing.payments.receipt`).
 *
 * **Não é nota fiscal.** O aviso está no topo e no rodapé do PDF, e é o mesmo texto
 * exposto em `PaymentReceipt::NOT_A_FISCAL_DOCUMENT`. A emissão de NFS-e é Fase 2
 * (RECONCILIACAO §5) e o Mercado Pago não oferece API pública para isso
 * (docs/integracoes/mercado-pago.md §9).
 *
 * O `Payment` chega pelo binding escopado da organização corrente (`HasPublicUlid` +
 * `BelongsToOrganization`): um pagamento de outra organização simplesmente não resolve.
 */
class PaymentReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceipt $receipts) {}

    public function show(Request $request, Payment $payment): Response
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        abort_unless($payment->isPaid(), 404, 'Recibo disponível apenas para pagamentos aprovados.');

        $bytes = $this->receipts->render($payment, $organization);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->receipts->filename($payment).'"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
