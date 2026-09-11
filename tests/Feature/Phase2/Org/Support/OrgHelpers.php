<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de etiquetas, relatórios, logs e "acessar como" (B-ORG)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes.
*/

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Permissions/Support/PermissionHelpers.php';

if (! function_exists('orgEnableTools')) {
    /**
     * Liga as flags da organização (configuração global E plano vigente).
     *
     * @param  list<string>  $flags
     */
    function orgEnableTools(Organization $organization, array $flags = ['tags', 'reports', 'audit_log'], bool $enabled = true): void
    {
        foreach ($flags as $flag) {
            config()->set('assinavelox.features.'.$flag, $enabled);
        }

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);

        foreach ($flags as $flag) {
            $features[$flag] = $enabled;
        }

        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('platformEnableTools')) {
    /**
     * @param  list<string>  $flags
     */
    function platformEnableTools(array $flags = ['admin_users', 'admin_audit', 'impersonation'], bool $enabled = true): void
    {
        foreach ($flags as $flag) {
            config()->set('assinavelox.features.'.$flag, $enabled);
        }
    }
}

if (! function_exists('orgEnvelope')) {
    /**
     * Envelope com marcos explícitos (relatórios dependem das datas).
     *
     * @param  array<string, mixed>  $attributes
     */
    function orgEnvelope(Organization $organization, User $creator, EnvelopeStatus $status, array $attributes = []): Envelope
    {
        $sentAt = Carbon::now()->subDays(3);

        $milestones = match ($status) {
            EnvelopeStatus::Completed => ['sent_at' => $sentAt, 'completed_at' => $sentAt->copy()->addHours(4)],
            EnvelopeStatus::Refused => ['sent_at' => $sentAt, 'refused_at' => $sentAt->copy()->addHours(2)],
            EnvelopeStatus::Expired => ['sent_at' => $sentAt, 'expired_at' => $sentAt->copy()->addDay()],
            EnvelopeStatus::Canceled => ['sent_at' => $sentAt, 'canceled_at' => $sentAt->copy()->addHour()],
            EnvelopeStatus::InProgress => ['sent_at' => $sentAt],
            default => [],
        };

        return Envelope::factory()->forOrganization($organization, $creator)->create([
            'status' => $status,
            ...$milestones,
            ...$attributes,
        ]);
    }
}
