<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('remember_token');
            $table->foreignId('current_organization_id')
                ->nullable()
                ->after('is_platform_admin')
                ->constrained('organizations')
                ->nullOnDelete();
            $table->string('timezone', 64)->nullable()->after('current_organization_id');
            $table->string('locale', 10)->default('pt_BR')->after('timezone');
            $table->timestamp('terms_accepted_at')->nullable()->after('locale');
            $table->string('terms_version', 32)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
            $table->dropColumn(['is_platform_admin', 'timezone', 'locale', 'terms_accepted_at', 'terms_version']);
        });
    }
};
