<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Restrito: o serviço move/apaga as subpastas antes de remover a pasta-mãe.
            $table->foreignId('parent_id')->nullable()->constrained('folders')->restrictOnDelete();
            $table->string('name', 120);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Observação: no MySQL, NULL em parent_id não colide no UNIQUE (pastas raiz com o mesmo
            // nome precisam ser barradas pelo serviço).
            $table->unique(['organization_id', 'parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
