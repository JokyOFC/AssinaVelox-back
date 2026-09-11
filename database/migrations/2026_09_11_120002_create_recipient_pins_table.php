<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.9 (C-CAN) — PIN do remetente, um por participante.
|
| `pin_hash` = password_hash(HMAC-SHA256("{recipient.ulid}|{pin}", segredo derivado da
| APP_KEY)). O PIN em claro nunca é gravado, nem o seu tamanho. Contadores de tentativa e
| bloqueio ficam aqui (e não no cache) para sobreviverem a reinício e serem auditáveis.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('pin_hash', 255);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->unsignedSmallInteger('lockouts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('envelope_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_pins');
    }
};
