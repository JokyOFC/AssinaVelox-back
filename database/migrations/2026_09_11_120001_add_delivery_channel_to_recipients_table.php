<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.9 (C-CAN, docs/fase-2/canais-e-pin.md) — canal de ENTREGA do convite por
| participante. Nulo = e-mail (comportamento da Fase 1). `sms`/`whatsapp` mandam, além do
| e-mail, um aviso com o link pelo canal escolhido. O método de autenticação continua em
| `recipients.auth_method` (texto, sem ENUM SQL) e o telefone em `recipients.phone` (E.164).
| Aditiva: nada existente muda.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('delivery_channel', 16)->nullable()->after('auth_method'); // DeliveryChannel; null = e-mail
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropColumn('delivery_channel');
        });
    }
};
