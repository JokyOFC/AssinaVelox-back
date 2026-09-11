<?php

use App\Enums\FolderAccessLevel;
use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\Team;
use App\Support\CurrentOrganization;
use App\Support\PermissionsFolderAccess;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/PermissionHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    enableCustomRoles();
});

/**
 * Organização com uma função "Revisor" (sem ver todos) liberada na pasta Locação, e
 * quatro documentos em andamento: o próprio, um em Locação, um em Jurídico e um solto.
 *
 * @return array<string, mixed>
 */
function folderScenario(array $permissions = [Permission::CreateEnvelopes, Permission::SendEnvelopes, Permission::ExportData, Permission::ViewReports]): array
{
    $ctx = createOrganizationWithOwner();
    $organization = $ctx['organization'];
    $owner = $ctx['owner'];

    $role = createCustomRole($organization, 'Revisor', $permissions);
    $user = attachWithCustomRole($organization, $role);

    $locacao = folderIn($organization, 'Locação');
    $juridico = folderIn($organization, 'Jurídico');

    $own = Envelope::factory()->forOrganization($organization, $user)->inProgress()->create(['title' => 'Contrato Próprio']);
    $inGranted = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(['title' => 'Contrato Locação', 'folder_id' => $locacao->id]);
    $inHidden = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(['title' => 'Contrato Jurídico', 'folder_id' => $juridico->id]);
    $loose = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(['title' => 'Contrato Solto']);

    Recipient::factory()->forEnvelope($inGranted)->notified()->create(['name' => 'Ana Locatária', 'email' => 'ana@exemplo.com.br']);
    Recipient::factory()->forEnvelope($inHidden)->notified()->create(['name' => 'Beto Jurídico', 'email' => 'beto@exemplo.com.br']);

    grantFolder($locacao, 'role', $role->id);

    return compact('organization', 'owner', 'role', 'user', 'locacao', 'juridico', 'own', 'inGranted', 'inHidden', 'loose');
}

test('função sem "ver todos" vê só os próprios documentos e os das pastas liberadas na listagem', function () {
    $ctx = folderScenario();

    actingAsMember($ctx['user'], $ctx['organization']);

    $props = $this->get(route('envelopes.index'))->assertOk()->viewData('page')['props'];

    expect(collect($props['envelopes']['data'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$ctx['own']->ulid, $ctx['inGranted']->ulid])->sort()->values()->all())
        ->and($props['summary']['total'])->toBe(2)
        ->and($props['tabs']['all'])->toBe(2);
});

test('a busca, as contagens da sidebar e o dashboard usam a mesma regra', function () {
    $ctx = folderScenario();

    actingAsMember($ctx['user'], $ctx['organization']);

    $search = $this->getJson(route('search.index', ['q' => 'Contrato']))->assertOk();
    expect(collect($search->json('envelopes'))->pluck('title')->sort()->values()->all())->toBe(['Contrato Locação', 'Contrato Próprio']);

    $recipients = $this->getJson(route('search.index', ['q' => 'exemplo.com.br']))->json('recipients');
    expect(collect($recipients)->pluck('name')->all())->toBe(['Ana Locatária']);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('counts.pending_envelopes', 2)
        ->where('greeting.pending_count', 2)
        ->where('recent_total', 2));
});

test('as exportações CSV só trazem o que a função pode ver', function () {
    $ctx = folderScenario();

    actingAsMember($ctx['user'], $ctx['organization']);

    $documents = $this->get(route('dashboard.export', ['range' => '30d']))->assertOk()->streamedContent();
    expect($documents)->toContain('Contrato Locação')
        ->toContain('Contrato Próprio')
        ->not->toContain('Contrato Jurídico')
        ->not->toContain('Contrato Solto');

    $signers = $this->get(route('recipients.export'))->assertOk()->streamedContent();
    expect($signers)->toContain('Ana Locatária')->not->toContain('Beto Jurídico');
});

test('documento de pasta sem acesso não abre nem por ULID direto; com acesso "visualizar" abre mas não edita', function () {
    $ctx = folderScenario();

    actingAsMember($ctx['user'], $ctx['organization']);

    $this->get(route('envelopes.show', $ctx['inHidden']))->assertForbidden();
    $this->get(route('envelopes.show', $ctx['loose']))->assertForbidden();
    $this->get(route('envelopes.show', $ctx['inGranted']))->assertOk();
    $this->post(route('envelopes.cancel', $ctx['inGranted']), ['reason' => 'teste'])->assertForbidden();

    CurrentOrganization::instance()->set($ctx['organization']);
    $gate = Gate::forUser($ctx['user']);
    expect($gate->allows('view', $ctx['inGranted']))->toBeTrue()
        ->and($gate->allows('download', $ctx['inGranted']))->toBeTrue()
        ->and($gate->allows('update', $ctx['inGranted']))->toBeFalse()
        ->and($gate->allows('cancel', $ctx['inGranted']))->toBeFalse();
    CurrentOrganization::instance()->clear();
});

test('acesso "gerenciar" à pasta permite editar, enviar e cancelar os documentos dela', function () {
    $ctx = folderScenario();
    PermissionsFolderAccess::sync($ctx['organization']->id, 'role', $ctx['role']->id, [$ctx['locacao']->id => FolderAccessLevel::Manage]);

    CurrentOrganization::instance()->set($ctx['organization']);
    $gate = Gate::forUser($ctx['user']);

    expect($gate->allows('update', $ctx['inGranted']))->toBeTrue()
        ->and($gate->allows('send', $ctx['inGranted']))->toBeTrue()
        ->and($gate->allows('cancel', $ctx['inGranted']))->toBeTrue()
        ->and($gate->allows('update', $ctx['inHidden']))->toBeFalse();
    CurrentOrganization::instance()->clear();
});

test('acesso por time e acesso direto também ampliam a visibilidade; o de outra pessoa não', function () {
    $ctx = folderScenario();
    $organization = $ctx['organization'];
    $operator = attachMember($organization, MembershipRole::Member);
    $bystander = attachMember($organization, MembershipRole::Member);

    $team = new Team;
    $team->forceFill(['organization_id' => $organization->id, 'name' => 'Jurídico interno'])->save();
    $team->memberships()->attach(membershipOf($operator, $organization)->id);
    grantFolder($ctx['juridico'], 'team', $team->id);
    grantFolder($ctx['locacao'], 'membership', membershipOf($operator, $organization)->id, FolderAccessLevel::Manage);

    actingAsMember($operator, $organization);
    $ids = collect($this->get(route('envelopes.index'))->viewData('page')['props']['envelopes']['data'])->pluck('id');
    expect($ids->sort()->values()->all())->toBe(collect([$ctx['inGranted']->ulid, $ctx['inHidden']->ulid])->sort()->values()->all());

    // Operador sem grants continua exatamente como na Fase 1 (só os próprios).
    actingAsMember($bystander, $organization);
    expect($this->get(route('envelopes.index'))->viewData('page')['props']['envelopes']['data'])->toBe([]);
    $this->get(route('envelopes.show', $ctx['inGranted']))->assertForbidden();
});

test('remover "ver todos" da função tira o acesso na hora, inclusive das contagens em cache', function () {
    $ctx = folderScenario([Permission::CreateEnvelopes, Permission::SendEnvelopes, Permission::ViewAllEnvelopes]);

    actingAsMember($ctx['user'], $ctx['organization']);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('counts.pending_envelopes', 4));
    $this->get(route('envelopes.show', $ctx['loose']))->assertOk();

    actingAsMember($ctx['owner'], $ctx['organization']);
    $this->patch(route('roles.update', $ctx['role']), ['permissions' => ['create_envelopes', 'send_envelopes']])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    actingAsMember($ctx['user'], $ctx['organization']);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('counts.pending_envelopes', 2));
    $this->get(route('envelopes.show', $ctx['loose']))->assertForbidden();
    $this->get(route('envelopes.show', $ctx['inGranted']))->assertOk();
});

test('retirar a pasta da função tira o acesso na hora', function () {
    $ctx = folderScenario();

    actingAsMember($ctx['user'], $ctx['organization']);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('counts.pending_envelopes', 2));

    actingAsMember($ctx['owner'], $ctx['organization']);
    $this->put(route('roles.folders', $ctx['role']), ['folders' => []])->assertSessionHasNoErrors();

    actingAsMember($ctx['user'], $ctx['organization']);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('counts.pending_envelopes', 1));
    $this->get(route('envelopes.show', $ctx['inGranted']))->assertForbidden();
});
