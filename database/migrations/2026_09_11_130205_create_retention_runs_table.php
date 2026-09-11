<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Fase 2 §2.19 (K-RET) — execuções do `retention:apply` (plataforma, sem organização).
| Só contagens no resumo: nada de título, nome ou caminho.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->boolean('dry_run')->default(false);
            $table->string('status', 16)->default('running'); // running | completed | failed
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['started_at'], 'retention_runs_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_runs');
    }
};
