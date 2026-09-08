<?php

use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Support\CurrentOrganization;

beforeEach(function () {
    CurrentOrganization::instance()->clear();
});

it('não filtra quando não há organização corrente', function () {
    $a = Envelope::factory()->create();
    $b = Envelope::factory()->create();

    expect(CurrentOrganization::instance()->has())->toBeFalse()
        ->and(Envelope::query()->count())->toBe(2)
        ->and(Envelope::query()->pluck('id')->all())->toEqualCanonicalizing([$a->id, $b->id]);
});

it('filtra pela organização corrente quando definida', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $a = Envelope::factory()->forOrganization($orgA)->create();
    Envelope::factory()->forOrganization($orgB)->count(2)->create();
    Recipient::factory()->forEnvelope($a)->create();

    CurrentOrganization::instance()->set($orgA);

    expect(Envelope::query()->count())->toBe(1)
        ->and(Envelope::query()->first()->is($a))->toBeTrue()
        ->and(Recipient::query()->count())->toBe(1)
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(3)
        ->and(Envelope::query()->withoutGlobalScope(OrganizationScope::class)->count())->toBe(3)
        ->and(Envelope::forOrganization($orgB)->count())->toBe(2)
        ->and(Envelope::forOrganization($orgB->id)->count())->toBe(2);

    CurrentOrganization::instance()->set($orgB);

    expect(Envelope::query()->count())->toBe(2)
        ->and(Recipient::query()->count())->toBe(0);
});

it('preenche organization_id no creating a partir da organização corrente', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    CurrentOrganization::instance()->set($organization);

    $folder = Folder::query()->create(['name' => 'Contratos', 'created_by_user_id' => $user->id]);
    $envelope = Envelope::query()->create(['title' => 'Contrato', 'created_by_user_id' => $user->id]);

    expect($folder->organization_id)->toBe($organization->id)
        ->and($envelope->organization_id)->toBe($organization->id)
        ->and($envelope->number)->toBe(1)
        ->and($envelope->organization->is($organization))->toBeTrue();
});

it('não sobrescreve organization_id explícito', function () {
    $current = Organization::factory()->create();
    $other = Organization::factory()->create();

    CurrentOrganization::instance()->set($current);

    $envelope = Envelope::factory()->forOrganization($other)->create();

    expect($envelope->organization_id)->toBe($other->id);
});

it('runAs troca a organização temporariamente e restaura a anterior', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    Envelope::factory()->forOrganization($orgA)->create();
    Envelope::factory()->forOrganization($orgB)->count(2)->create();

    $current = CurrentOrganization::instance();
    $current->set($orgA);

    $countInB = $current->runAs($orgB, fn () => Envelope::query()->count());
    $countWithout = $current->runAs(null, fn () => Envelope::query()->count());

    expect($countInB)->toBe(2)
        ->and($countWithout)->toBe(3)
        ->and($current->id())->toBe($orgA->id)
        ->and(Envelope::query()->count())->toBe(1);
});

it('resolve o mesmo singleton via container', function () {
    $organization = Organization::factory()->create();

    CurrentOrganization::instance()->set($organization);

    expect(app(CurrentOrganization::class)->id())->toBe($organization->id)
        ->and(app(CurrentOrganization::class))->toBe(CurrentOrganization::instance());
});
