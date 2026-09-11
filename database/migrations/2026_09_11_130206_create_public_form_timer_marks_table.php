<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.19 (K-RET, item 5) — marcas de uso único do carimbo de tempo mínimo do formulário
| público (docs/fase-2/formulario-publico.md §7), que antes viviam no cache.
|
| `token_digest` = SHA-256 do ULID do formulário + identidade do carimbo (vetor de
| inicialização e texto cifrado — nunca o carimbo em si). O consumo é um INSERT com unicidade:
| de dois envios simultâneos com o mesmo carimbo, só um grava. `expires_at` = instante em que o
| próprio carimbo venceria (+1 min); a limpeza agendada (`public-forms:prune-timer-marks`) apaga
| o que já passou disso. Sem FK nem organização: a marca não identifica ninguém e dura <= 6 h.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_form_timer_marks', function (Blueprint $table): void {
            $table->id();
            $table->char('token_digest', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['expires_at'], 'public_form_timer_marks_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_form_timer_marks');
    }
};
