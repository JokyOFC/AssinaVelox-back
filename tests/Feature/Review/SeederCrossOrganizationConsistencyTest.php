<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (isolamento/autorização) — seeders sem dados cruzados
|--------------------------------------------------------------------------
| Depois de rodar o DatabaseSeeder, nenhuma linha escopada pode referenciar um pai
| (envelope, recipient, documento, pasta, assinatura) de OUTRA organização.
*/

use App\Enums\MembershipStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/**
 * Linhas de $table cujo organization_id difere do organization_id do pai em $parentTable.
 */
function crossOrgRows(string $table, string $foreignKey, string $parentTable): int
{
    $parentAlias = $parentTable === $table ? 'parent_row' : $parentTable;

    return DB::table($table)
        ->join("{$parentTable} as {$parentAlias}", "{$parentAlias}.id", '=', "{$table}.{$foreignKey}")
        ->whereColumn("{$table}.organization_id", '!=', "{$parentAlias}.organization_id")
        ->count();
}

test('tabelas filhas de envelopes/recipients/documentos pertencem à mesma organização do pai', function () {
    $checks = [
        ['documents', 'envelope_id', 'envelopes'],
        ['document_versions', 'document_id', 'documents'],
        ['recipients', 'envelope_id', 'envelopes'],
        ['recipient_access_links', 'envelope_id', 'envelopes'],
        ['recipient_access_links', 'recipient_id', 'recipients'],
        ['signing_sessions', 'envelope_id', 'envelopes'],
        ['signing_sessions', 'recipient_id', 'recipients'],
        ['auth_challenges', 'envelope_id', 'envelopes'],
        ['auth_challenges', 'recipient_id', 'recipients'],
        ['signing_fields', 'envelope_id', 'envelopes'],
        ['signing_fields', 'recipient_id', 'recipients'],
        ['signing_fields', 'document_version_id', 'document_versions'],
        ['signing_field_values', 'envelope_id', 'envelopes'],
        ['signing_field_values', 'recipient_id', 'recipients'],
        ['signature_acceptances', 'envelope_id', 'envelopes'],
        ['signature_acceptances', 'recipient_id', 'recipients'],
        ['audit_events', 'envelope_id', 'envelopes'],
        ['audit_events', 'recipient_id', 'recipients'],
        ['delivery_attempts', 'envelope_id', 'envelopes'],
        ['delivery_attempts', 'recipient_id', 'recipients'],
        ['verification_records', 'envelope_id', 'envelopes'],
        ['plan_consumptions', 'envelope_id', 'envelopes'],
        ['plan_consumptions', 'subscription_id', 'subscriptions'],
        ['payments', 'subscription_id', 'subscriptions'],
        ['envelopes', 'folder_id', 'folders'],
        ['folders', 'parent_id', 'folders'],
    ];

    foreach ($checks as [$table, $foreignKey, $parentTable]) {
        expect(crossOrgRows($table, $foreignKey, $parentTable))
            ->toBe(0, "{$table}.{$foreignKey} aponta para {$parentTable} de outra organização");
    }
});

test('recipient referenciado por campos/sessões/links pertence ao mesmo envelope da linha', function () {
    foreach (['signing_fields', 'signing_field_values', 'signature_acceptances', 'recipient_access_links', 'signing_sessions', 'auth_challenges', 'delivery_attempts', 'audit_events'] as $table) {
        $mismatch = DB::table($table)
            ->join('recipients', 'recipients.id', '=', "{$table}.recipient_id")
            ->whereColumn('recipients.envelope_id', '!=', "{$table}.envelope_id")
            ->count();

        expect($mismatch)->toBe(0, "{$table}.recipient_id pertence a outro envelope");
    }
});

test('criadores de envelopes/pastas e emissores de convites são membros da organização', function () {
    $envelopeCreators = DB::table('envelopes')
        ->leftJoin('memberships', fn ($join) => $join
            ->on('memberships.user_id', '=', 'envelopes.created_by_user_id')
            ->on('memberships.organization_id', '=', 'envelopes.organization_id'))
        ->whereNull('memberships.id')
        ->count();

    $folderCreators = DB::table('folders')
        ->whereNotNull('created_by_user_id')
        ->leftJoin('memberships', fn ($join) => $join
            ->on('memberships.user_id', '=', 'folders.created_by_user_id')
            ->on('memberships.organization_id', '=', 'folders.organization_id'))
        ->whereNull('memberships.id')
        ->count();

    $inviters = DB::table('membership_invitations')
        ->whereNotNull('invited_by_user_id')
        ->leftJoin('memberships', fn ($join) => $join
            ->on('memberships.user_id', '=', 'membership_invitations.invited_by_user_id')
            ->on('memberships.organization_id', '=', 'membership_invitations.organization_id'))
        ->whereNull('memberships.id')
        ->count();

    expect($envelopeCreators)->toBe(0)
        ->and($folderCreators)->toBe(0)
        ->and($inviters)->toBe(0);
});

test('cada organização demo tem exatamente uma assinatura ativa, um owner ativo e o admin da plataforma não é membro', function () {
    Organization::query()->each(function (Organization $organization): void {
        expect(DB::table('subscriptions')->where('organization_id', $organization->id)->where('status', SubscriptionStatus::Active->value)->count())->toBe(1)
            ->and(DB::table('memberships')->where('organization_id', $organization->id)->where('role', 'owner')->where('status', MembershipStatus::Active->value)->count())->toBe(1);
    });

    $platformAdminMemberships = DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.is_platform_admin', true)
        ->count();

    expect($platformAdminMemberships)->toBe(0);

    // users.current_organization_id sempre aponta para uma organização em que o usuário é membro.
    $danglingCurrent = DB::table('users')
        ->whereNotNull('current_organization_id')
        ->leftJoin('memberships', fn ($join) => $join
            ->on('memberships.user_id', '=', 'users.id')
            ->on('memberships.organization_id', '=', 'users.current_organization_id'))
        ->whereNull('memberships.id')
        ->count();

    expect($danglingCurrent)->toBe(0);
});
