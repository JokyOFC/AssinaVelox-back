<?php

namespace App\Providers;

use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Policies\EnvelopePolicy;
use App\Policies\FolderPolicy;
use App\Policies\MembershipInvitationPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\OrganizationPolicy;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Policies por recurso e route model binding escopado dos models SEM escopo global
 * (Membership e MembershipInvitation) — ver docs/autorizacao-e-isolamento.md.
 */
class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected array $policies = [
        Envelope::class => EnvelopePolicy::class,
        Folder::class => FolderPolicy::class,
        Membership::class => MembershipPolicy::class,
        MembershipInvitation::class => MembershipInvitationPolicy::class,
        Organization::class => OrganizationPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // {membership}: id inteiro, sempre dentro da organização corrente → 404 fora dela.
        Route::bind('membership', function (string $value): Membership {
            $organizationId = CurrentOrganization::instance()->id();

            abort_if($organizationId === null || ! ctype_digit($value), 404);

            return Membership::query()
                ->with(['user', 'organization'])
                ->where('organization_id', $organizationId)
                ->whereKey((int) $value)
                ->firstOrFail();
        });

        // {invitation}: ulid, sempre dentro da organização corrente → 404 fora dela.
        Route::bind('invitation', function (string $value): MembershipInvitation {
            $organizationId = CurrentOrganization::instance()->id();

            abort_if($organizationId === null, 404);

            return MembershipInvitation::query()
                ->with(['inviter', 'organization'])
                ->where('organization_id', $organizationId)
                ->where('ulid', $value)
                ->firstOrFail();
        });
    }
}
