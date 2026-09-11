<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 §2.4 — ação registrada no aceite (App\Enums\AcceptanceAction):
 * `sign` (signatário, Fase 1), `witness` (testemunha) ou `approve` (aprovador).
 *
 * Default `sign`: todo aceite gravado antes desta migration é de signatário, e continua sendo.
 * String, não ENUM de SQL (compatível com MySQL e SQLite; validação no enum PHP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_acceptances', function (Blueprint $table): void {
            $table->string('action', 32)->default('sign')->after('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('signature_acceptances', function (Blueprint $table): void {
            $table->dropColumn('action');
        });
    }
};
