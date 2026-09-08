<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32); // MembershipRole: owner | admin | member
            $table->string('status', 32)->default('active'); // MembershipStatus: active | suspended
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('membership_invitations', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 32); // MembershipRole
            $table->char('token_digest', 64)->unique();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_invitations');
        Schema::dropIfExists('memberships');
    }
};
