<?php

use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VerificationRecord;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\PlatformAdminSeeder;

it('PlanSeeder é idempotente e cria o catálogo comercial: Grátis privado + Básico, Profissional e Empresarial', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);

    $plan = fn (string $code): ?Plan => Plan::query()->where('code', $code)->first();

    expect(Plan::query()->count())->toBe(4)
        ->and(Plan::free()?->envelope_quota)->toBe(5)
        ->and(Plan::free()?->user_quota)->toBe(1)
        ->and(Plan::free()?->price_cents)->toBe(0)
        ->and(Plan::free()?->is_public)->toBeFalse()
        ->and($plan('basic')?->price_cents)->toBe(14_900)
        ->and($plan('basic')?->envelope_quota)->toBe(5)
        ->and($plan('professional')?->price_cents)->toBe(24_900)
        ->and($plan('professional')?->envelope_quota)->toBe(50)
        ->and($plan('professional')?->is_sandbox)->toBeFalse()
        ->and($plan('professional')?->is_public)->toBeTrue()
        ->and($plan('enterprise')?->price_cents)->toBe(34_900)
        ->and($plan('enterprise')?->envelope_quota)->toBe(100)
        ->and($plan('enterprise')?->features['sms_whatsapp'])->toBeTrue()
        ->and($plan('basic')?->features['sms_whatsapp'] ?? false)->toBeFalse();
});

it('DatabaseSeeder popula admin e organizações demo em testing', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', PlatformAdminSeeder::EMAIL)->first();

    expect($admin)->not->toBeNull()
        ->and($admin->is_platform_admin)->toBeTrue()
        ->and(Organization::query()->count())->toBe(2)
        ->and(Envelope::query()->count())->toBeGreaterThanOrEqual(15)
        ->and(Envelope::query()->where('status', EnvelopeStatus::Completed->value)->count())->toBeGreaterThanOrEqual(3)
        ->and(Envelope::query()->where('status', EnvelopeStatus::Refused->value)->count())->toBeGreaterThanOrEqual(1)
        ->and(Envelope::query()->where('status', EnvelopeStatus::Expired->value)->count())->toBeGreaterThanOrEqual(1)
        ->and(Subscription::query()->whereHas('plan', fn ($q) => $q->where('code', 'professional'))->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBeGreaterThan(50);

    // Numeração por organização começa em 1 em cada uma.
    Organization::query()->each(function (Organization $organization) {
        expect(Envelope::forOrganization($organization)->min('number'))->toBe(1);
    });

    // Todo envelope concluído tem registro de verificação e versão final.
    Envelope::query()->where('status', EnvelopeStatus::Completed->value)->each(function (Envelope $envelope) {
        expect($envelope->final_document_version_id)->not->toBeNull()
            ->and(VerificationRecord::query()->where('envelope_id', $envelope->id)->exists())->toBeTrue()
            ->and($envelope->recipients->every(fn ($r) => $r->status->value === 'signed'))->toBeTrue();
    });
});
