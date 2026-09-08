<?php

use App\Enums\MembershipRole;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de segurança — subtítulo de Documentos e visibilidade por papel
|--------------------------------------------------------------------------
| RECONCILIACAO Q7 / docs/autorizacao-e-isolamento.md §2: "owner/admin veem todos os
| envelopes da organização; member vê apenas os que criou". Permissions::matrix() reforça:
| `view_all_envelopes` só para owner/admin.
|
| ROUTES_AND_PAGES §2.5 declara `summary: { total, awaiting, storage_used_bytes }` como o
| subtítulo da página, e envelopes/index.tsx o renderiza como
| "N documentos · N aguardando assinatura · X usados".
|
| `total` e `awaiting` passam por EnvelopeVisibility; `storage_used_bytes` não:
| EnvelopeController::index usa `DocumentVersion::query()->sum('size_bytes')`, que só tem o
| escopo global de ORGANIZAÇÃO. Um Operador que não pode ver nenhum documento da conta lê
| o volume de armazenamento de todos eles — e a linha fica incoerente
| ("0 documentos · 120,56 KB usados").
*/

beforeEach(fn () => $this->withoutVite());

test('o Operador vê apenas o armazenamento dos documentos que pode ver', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create();
    $document = Document::factory()->forEnvelope($envelope)->create();
    DocumentVersion::factory()->forDocument($document)->create(['size_bytes' => 123456]);

    actingAsMember($member, $organization);

    $props = $this->get(route('envelopes.index'))->assertOk()->viewData('page')['props'];

    expect($props['summary']['total'])->toBe(0)
        ->and($props['summary']['storage_used_bytes'])->toBe(0);
});

test('o proprietário vê o armazenamento da organização', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create();
    $document = Document::factory()->forEnvelope($envelope)->create();
    DocumentVersion::factory()->forDocument($document)->create(['size_bytes' => 123456]);

    actingAsMember($owner, $organization);

    $props = $this->get(route('envelopes.index'))->assertOk()->viewData('page')['props'];

    expect($props['summary']['total'])->toBe(1)
        ->and($props['summary']['storage_used_bytes'])->toBe(123456);
});
