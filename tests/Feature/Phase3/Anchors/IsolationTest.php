<?php

use App\Enums\MembershipRole;
use App\Models\AnchorScan;
use App\Models\FieldSuggestion;
use App\Services\Anchors\SuggestionStatus;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/AnchorHelpers.php';

/*
| Isolamento: organização, envelope e permissão de edição. Uma sugestão só é alcançável pelo
| envelope a que pertence, dentro da organização corrente, por quem pode editar o envelope.
*/

beforeEach(function () {
    anchorsRequirePdftool();
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    anchorsIsolatedDisk($this->work);

    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    anchorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);

    $pdf = anchorsPdf($this->work, [['texts' => [[72, 600, '{{data:locatario}}']]]]);
    $people = [['name' => 'Ana Souza', 'email' => 'ana@example.com', 'role_label' => 'Locatário']];
    $this->a = anchorsEnvelope($this->organization, $this->owner, $pdf, $people)['envelope'];
    $this->b = anchorsEnvelope($this->organization, $this->owner, $pdf, $people)['envelope'];
    $this->suggestionB = $this->postJson(route('anchors.envelope.detect', $this->b))->assertStatus(202)->json('suggestions.0.id');
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('sugestão de outro envelope responde 404', function () {
    $this->postJson(route('anchors.suggestions.accept', [$this->a, $this->suggestionB]))->assertNotFound();
    $this->postJson(route('anchors.suggestions.discard', [$this->a, $this->suggestionB]))->assertNotFound();

    expect(FieldSuggestion::query()->where('ulid', $this->suggestionB)->value('status'))->toBe(SuggestionStatus::Pending);
});

test('outra organização não enxerga nem revisa', function () {
    ['organization' => $other, 'owner' => $stranger] = createOrganizationWithOwner();
    anchorsEnable($other);
    actingAsMember($stranger, $other);

    $this->getJson(route('anchors.envelope.index', $this->b))->assertNotFound();
    $this->postJson(route('anchors.envelope.detect', $this->b))->assertNotFound();
    $this->postJson(route('anchors.suggestions.accept', [$this->b, $this->suggestionB]))->assertNotFound();
    $this->postJson(route('anchors.suggestions.accept_all', $this->b))->assertNotFound();

    expect(FieldSuggestion::query()->withoutGlobalScopes()->where('ulid', $this->suggestionB)->value('status'))->toBe(SuggestionStatus::Pending)
        ->and(AnchorScan::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('quem não pode editar o envelope recebe 403', function () {
    $member = attachMember($this->organization, MembershipRole::Member);
    actingAsMember($member, $this->organization);

    $this->getJson(route('anchors.envelope.index', $this->b))->assertForbidden();
    $this->postJson(route('anchors.envelope.detect', $this->b))->assertForbidden();
    $this->postJson(route('anchors.suggestions.accept', [$this->b, $this->suggestionB]))->assertForbidden();
});

test('convidado é redirecionado para o login', function () {
    auth()->logout();

    $this->get(route('anchors.envelope.index', $this->b))->assertRedirect();
});
