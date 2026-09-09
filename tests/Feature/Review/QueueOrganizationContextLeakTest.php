<?php

use App\Enums\EnvelopeStatus;
use App\Jobs\Envelopes\ResendPendingInvitations;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Envelopes\Sending\ResendInvitations;
use App\Support\CurrentOrganization;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final de segurança — vazamento da organização corrente entre jobs
|--------------------------------------------------------------------------
| `App\Support\CurrentOrganization` é registrado como SINGLETON em
| AppServiceProvider::register() (linha 31). Em HTTP o middleware
| `ResetCurrentOrganization` limpa o singleton antes e depois de cada requisição
| (bootstrap/app.php). Em fila NÃO existe equivalente: o worker do Laravel só chama
| `$app->forgetScopedInstances()` a cada loop
| (vendor/laravel/framework/src/Illuminate/Queue/QueueServiceProvider.php:263), e isso
| descarta apenas o que foi registrado com `scoped()` — não `singleton()`.
|
| `App\Jobs\Envelopes\ResendPendingInvitations::handle()` (linha 64) faz
| `CurrentOrganization::instance()->set($organization)` e nunca restaura o valor
| anterior: nem no caminho feliz, nem no `return` antecipado de "membership não
| encontrada" (linha 74), nem em exceção. O processo do worker fica, a partir daí,
| carimbado com a organização daquele job.
|
| Consequência: o PRÓXIMO job processado pelo mesmo processo roda com o escopo global
| de outra organização. `supervisor-default` do Horizon atende `default`,
| `notifications` e `billing` no mesmo processo (config/horizon.php:207-212), e é
| exatamente na fila `notifications` que este job roda
| (App\Jobs\Envelopes\ResendPendingInvitations::__construct).
|
| Os dois testes abaixo falham hoje.
*/

beforeEach(fn () => $this->withoutVite());

it('não deixa a organização do job vazar para o processo do worker', function () {
    ['organization' => $organizationA, 'owner' => $ownerA] = createOrganizationWithOwner();

    Envelope::factory()->forOrganization($organizationA, $ownerA)->create([
        'status' => EnvelopeStatus::InProgress,
    ]);

    CurrentOrganization::instance()->clear();

    (new ResendPendingInvitations($organizationA->getKey()))->handle(app(ResendInvitations::class));

    // Um worker de fila não tem `ResetCurrentOrganization`: o que o job deixar aqui vale
    // para o job seguinte do mesmo processo.
    expect(CurrentOrganization::instance()->id())->toBeNull();
});

it('não contamina o escopo global do job seguinte, de outra organização', function () {
    ['organization' => $organizationA, 'owner' => $ownerA] = createOrganizationWithOwner();
    ['organization' => $organizationB, 'owner' => $ownerB] = createOrganizationWithOwner();

    Envelope::factory()->forOrganization($organizationA, $ownerA)->create([
        'status' => EnvelopeStatus::InProgress,
    ]);

    $envelopeB = Envelope::factory()->forOrganization($organizationB, $ownerB)->create([
        'status' => EnvelopeStatus::InProgress,
    ]);

    Recipient::factory()->forEnvelope($envelopeB)->count(2)->create();

    CurrentOrganization::instance()->clear();

    // Job 1 do worker: organização A.
    (new ResendPendingInvitations($organizationA->getKey()))->handle(app(ResendInvitations::class));

    // Job 2 do MESMO processo: trabalha sobre um envelope da organização B, carregado
    // sem escopo (como os jobs fazem). As relações, porém, aplicam o escopo global —
    // que continua apontando para a organização A.
    $envelopeB = Envelope::withoutOrganizationScope()->whereKey($envelopeB->getKey())->firstOrFail();

    expect($envelopeB->recipients()->count())->toBe(2)
        ->and(Envelope::query()->pluck('organization_id')->unique()->all())
        ->not->toBe([$organizationA->getKey()]);
});
