<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recibo interno em PDF (ROUTES §1.2 billing.payments.receipt) — não é NF-e.
 * // TODO(Wave B - cobrança): Blade → DOMPDF.
 */
class PaymentReceiptController extends Controller
{
    public function show(Request $request, Payment $payment): Response
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        abort_unless($payment->isPaid(), 404, 'Recibo disponível apenas para pagamentos aprovados.');

        abort(404, 'Recibo indisponível.');
    }
}
