<?php

use App\Enums\MembershipRole;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\User;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de isolamento — `summary` de /documentos e a visibilidade por papel
|--------------------------------------------------------------------------
| RECONCILIACAO Q7 (prevalece): "`member` vê apenas envelopes próprios; **contagens
| respeitam o escopo**". ROUTES_AND_PAGES §2.5 declara `summary: { total, awaiting,
| storage_used_bytes }` como um bloco só ("subtítulo") e o front concatena os três no mesmo
| texto (`resources/js/pages/envelopes/index.tsx:370`):
|   "{total} documentos · {awaiting} aguardando assinatura · {storage} usados".
|
| `EnvelopeController@index` calcula `total`/`awaiting` por EnvelopeVisibility, mas
| `storage_used_bytes` sai de `DocumentVersion::query()->sum('size_bytes')` (linha 107) —
| só o escopo global de organização, sem o filtro do papel. Um `member` sem nenhum documento
| próprio lê "0 documentos · 0 aguardando assinatura · 964 KB usados", ou seja, o volume
| agregado dos documentos dos colegas.
|
| A mesma consulta também soma versões de envelopes já excluídos (soft delete), enquanto
| `total` não os conta.
*/

beforeEach(fn () => $this->withoutVite());

function envelopeWithStoredVersion(Organization $organization, User $creator, int $bytes, string $state = 'inProgress'): Envelope
{
    $envelope = Envelope::factory()->forOrganization($organization, $creator)->{$state}()->create();

    $document = Document::factory()->create([
        'envelope_id' => $envelope->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    DocumentVersion::factory()->create([
        'document_id' => $document->getKey(),
        'organization_id' => $organization->getKey(),
        'size_bytes' => $bytes,
    ]);

    return $envelope;
}

test('o resumo de /documentos respeita a visibilidade do papel também no armazenamento', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);

    envelopeWithStoredVersion($organization, $owner, 987_654);
    envelopeWithStoredVersion($organization, $member, 111);

    actingAsMember($member, $organization);
    $summary = $this->get(route('envelopes.index'))->viewData('page')['props']['summary'];

    expect($summary['total'])->toBe(1)
        ->and($summary['storage_used_bytes'])->toBe(111);

    actingAsMember($owner, $organization);
    $summary = $this->get(route('envelopes.index'))->viewData('page')['props']['summary'];

    expect($summary['total'])->toBe(2)
        ->and($summary['storage_used_bytes'])->toBe(987_765);
});

test('o resumo de /documentos não conta o armazenamento de rascunhos excluídos', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = envelopeWithStoredVersion($organization, $owner, 555, 'draft');

    actingAsMember($owner, $organization);
    $this->delete(route('envelopes.destroy', $envelope))->assertRedirect(route('envelopes.index'));

    $summary = $this->get(route('envelopes.index'))->viewData('page')['props']['summary'];

    expect($summary['total'])->toBe(0)
        ->and($summary['storage_used_bytes'])->toBe(0);
});
