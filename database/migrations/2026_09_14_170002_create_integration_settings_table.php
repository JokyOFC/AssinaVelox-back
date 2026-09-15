<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 3 §3.9 (G-EMBED) — configurações de integração da organização
 * (docs/fase-3/widget-embutido.md §4). Uma linha por organização.
 *
 * `allowed_origins`: lista JSON de origens EXATAS (`https://host[:porta]`) que podem hospedar o
 * widget de assinatura embutida. Sem curinga, sem caminho, sem usuário/senha; `http://localhost`
 * e `http://127.0.0.1` só fora de produção (App\Services\Embed\AllowedOrigins). A origem de cada
 * sessão embutida precisa estar nesta lista no momento da criação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('allowed_origins')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
    }
};
