<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2: preferências de notificação por membership (usuário × organização), eventos × canais.
 * JSON no formato {"recipient_signed": ["mail","database"], ...}; null = padrões do catálogo
 * (App\Services\Organizations\NotificationPreferences). Exceção de área documentada em
 * docs/autorizacao-e-isolamento.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
