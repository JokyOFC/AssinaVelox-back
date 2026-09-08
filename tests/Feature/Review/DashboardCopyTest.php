<?php

use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — subtítulo do Dashboard
|--------------------------------------------------------------------------
| DESIGN_SYSTEM §6.2 (copy exata): "Quarta-feira, 3 de setembro de 2026 · 23
| documentos aguardam assinatura". O backend envia `greeting.date_label` em
| `l, d \d\e F` → "Quarta-feira, 03 de setembro" (dia com zero à esquerda e sem o
| ano). A regra de formatação fica no backend, então o teste é aqui.
*/

it('formata greeting.date_label como no mock: dia sem zero à esquerda e com o ano', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    Carbon::setTestNow(Carbon::parse('2026-09-03 14:00:00', 'America/Sao_Paulo'));

    try {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('greeting.date_label', 'Quinta-feira, 3 de setembro de 2026'));
    } finally {
        Carbon::setTestNow();
    }
});
