<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (fidelidade de design) — contagem de assentos em Usuários
|--------------------------------------------------------------------------
| `pages/members/index.tsx` imprime na MESMA tela dois números vindos de `seats`:
|   - cabeçalho (linha 497): "{seats.used} de {seats.limit} assentos ... em uso"
|   - rodapé da tabela (linha 583): "{seats.available} assentos disponíveis"
|
| `SeatUsage::for()` calculava `available = limit - used - pending_invitations`,
| mas `used` contava só memberships ativas. Com 1 convite pendente a tela mostrava
| "3 de 10 assentos em uso" e, logo abaixo, "6 assentos disponíveis" — 3 + 6 ≠ 10.
|
| Correção: `used` passou a contar memberships ativas + convites pendentes e
| `available = limit - used` (DESIGN_SYSTEM §2). A asserção original deste teste era
| contraditória — exigia ao mesmo tempo `available == limit - used - pending`,
| `used + available == limit` e `pending == 1`, o que só fecharia com `pending == 0`.
| Pior: quando used + pending == limit o botão "Convidar usuário" fica
| desabilitado enquanto o cabeçalho ainda anuncia assentos livres.
|
| A própria aplicação já assume a definição correta em
| `SeatUsage::unavailableMessage()`: "(N de LIMIT em uso, contando convites pendentes)".
*/

use App\Models\MembershipInvitation;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('os dois números de assentos exibidos em /usuarios fecham com o limite do plano', function () {
    $context = createOrganizationWithOwner(['name' => 'Imobiliária Horizonte']);
    $organization = $context['organization'];
    $owner = $context['owner'];

    // Plano com folga para que o convite pendente não seja bloqueado por cota.
    $organization->currentSubscription()->first()?->plan()->first()?->update(['user_quota' => 10]);

    MembershipInvitation::factory()->create([
        'organization_id' => $organization->id,
        'invited_by_user_id' => $owner->id,
        'email' => 'novo.colaborador@horizonte.demo',
        'accepted_at' => null,
        'revoked_at' => null,
        'expires_at' => now()->addDays(7),
    ]);

    actingAsMember($owner, $organization);

    $this->get(route('members.index'))->assertInertia(function (Assert $page) {
        $seats = $page->toArray()['props']['seats'];

        expect($seats['limit'])->toBe(10);
        expect($seats['pending_invitations'])->toBe(1);

        // Definição única de "assento ocupado" (DESIGN_SYSTEM §2, tela Usuários: "6 de 10
        // assentos ... em uso · 1 convite pendente" + rodapé "4 assentos disponíveis"):
        // `used` conta memberships ativas E convites pendentes, e `available = limit - used`.
        expect($seats['used'])->toBe(
            $seats['active_memberships'] + $seats['pending_invitations'],
            '`used` precisa contar os convites pendentes, como já faz SeatUsage::unavailableMessage()'
        );
        expect($seats['available'])->toBe($seats['limit'] - $seats['used']);

        // O cabeçalho usa `used` e o rodapé usa `available`: os dois têm de somar o limite.
        expect($seats['used'] + $seats['available'])->toBe(
            $seats['limit'],
            'A tela mostra "'.$seats['used'].' de '.$seats['limit'].' assentos em uso" e "'
                .$seats['available'].' assentos disponíveis" ao mesmo tempo — os números não fecham '
                .'porque `used` ignora os convites pendentes que `available` desconta.'
        );
    });
});
