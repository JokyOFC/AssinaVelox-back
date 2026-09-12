<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, onda D — D-PAY (roadmap §2.20). Colunas aditivas em `payments`, todas opcionais ou com
 * padrão, preenchidas só com a flag `extended_payments` ligada:
 *
 * - `payment_type_id`: família do meio devolvida por GET /v1/payments/{id} (`bank_transfer` = Pix,
 *   `ticket` = boleto, `credit_card`…); nunca dado de cartão;
 * - `expires_at`: `date_of_expiration` do provedor para Pix/boleto pendentes;
 * - `refunded_cents`: soma estornada confirmada pelo provedor (`transaction_amount_refunded`);
 * - `cancelled_at`: quando o cancelamento de um pendente foi aplicado;
 * - `provider_updated_at`: `date_last_updated` do provedor (base da conciliação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_type_id', 32)->nullable()->after('payment_method_id');
            $table->timestamp('expires_at')->nullable()->after('paid_at');
            $table->unsignedInteger('refunded_cents')->default(0)->after('amount_cents');
            $table->timestamp('cancelled_at')->nullable()->after('activated_at');
            $table->timestamp('provider_updated_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payment_type_id', 'expires_at', 'refunded_cents', 'cancelled_at', 'provider_updated_at']);
        });
    }
};
