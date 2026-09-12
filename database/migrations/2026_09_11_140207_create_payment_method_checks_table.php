<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, onda D — D-PAY. Registro de cada consulta a GET /v1/payment_methods (meios da conta
 * vendedora): o checkout só oferece uma família (Pix, boleto, cartão) que a conta tenha ativa.
 * `methods` guarda só `id`, `name`, `payment_type_id` e `status` de cada meio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_checks', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('environment', 32);
            $table->string('status', 16); // ok | failed
            $table->json('methods')->nullable();
            $table->string('error', 191)->nullable();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['provider', 'environment', 'status', 'checked_at'], 'payment_method_checks_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_checks');
    }
};
