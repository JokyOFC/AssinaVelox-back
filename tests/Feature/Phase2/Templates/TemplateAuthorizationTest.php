<?php

use App\Enums\MembershipRole;
use App\Enums\Permission;
use App\Models\Envelope;
use App\Models\Template;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/TemplateHelpers.php';
require_once __DIR__.'/../Permissions/Support/PermissionHelpers.php';

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization);

    $this->template = templateHtml($this->organization, $this->owner, '<p>Eu, {{nome}}.</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

describe('permissão manage_templates', function () {
    test('operador (sem manage_templates) vê e usa, mas não cria nem edita', function () {
        $member = attachMember($this->organization, MembershipRole::Member);
        actingAsMember($member, $this->organization);

        $this->get(route('templates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)
            ->where('can.use', true)
            ->has('templates', 1));

        $this->get(route('envelopes.create', ['template' => $this->template->ulid]))
            ->assertInertia(fn (Assert $page) => $page->component('templates/use'));

        $this->post(route('templates.store'), ['name' => 'Novo', 'source_type' => 'html'])->assertForbidden();
        $this->get(route('templates.edit', $this->template))->assertForbidden();
        $this->put(route('templates.update', $this->template), [
            'roles' => [['ref' => 'r1', 'name' => 'Parte', 'participant_role' => 'signer']],
        ])->assertForbidden();
        $this->post(route('templates.duplicate', $this->template))->assertForbidden();
        $this->post(route('templates.archive', $this->template))->assertForbidden();
        $this->get(route('templates.source.show', $this->template))->assertForbidden();

        expect(Template::query()->count())->toBe(1)
            ->and($this->template->fresh()->currentVersion->version_number)->toBe(2);
    });

    test('administrador pode criar e editar', function () {
        $admin = attachMember($this->organization, MembershipRole::Admin);
        actingAsMember($admin, $this->organization);

        $this->post(route('templates.store'), [
            'name' => 'Procuração simples',
            'category' => 'Jurídico',
            'source_type' => 'html',
        ])->assertRedirect();

        expect(Template::query()->where('name', 'Procuração simples')->exists())->toBeTrue();

        $this->get(route('templates.edit', $this->template))->assertOk();
    });

    test('função personalizada com manage_templates edita; sem create_envelopes não usa', function () {
        enableCustomRoles();
        $role = createCustomRole($this->organization, 'Curador de modelos', [Permission::ManageTemplates]);
        $curator = attachWithCustomRole($this->organization, $role);
        actingAsMember($curator, $this->organization);

        $this->get(route('templates.edit', $this->template))->assertOk();
        $this->put(route('templates.update', $this->template), [
            'html_body' => '<p>Eu, {{nome}}, concordo.</p>',
            'variables' => [['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true]],
            'roles' => [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        expect($this->template->fresh()->currentVersion->version_number)->toBe(3);

        $roleIds = templateRoleIds($this->template);
        $this->post(route('templates.use', $this->template), [
            'values' => ['nome' => 'Ana'],
            'participants' => [$roleIds['Locatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com']],
        ])->assertForbidden();

        expect(Envelope::query()->count())->toBe(0);
    });
});

describe('isolamento entre organizações', function () {
    beforeEach(function () {
        ['organization' => $this->other, 'owner' => $this->otherOwner] = createOrganizationWithOwner();
        templatesEnable($this->other);
    });

    test('ULID de modelo de outra organização é 404 em todas as rotas', function () {
        actingAsMember($this->otherOwner, $this->other);

        $roleIds = templateRoleIds($this->template);

        $this->get(route('templates.edit', $this->template->ulid))->assertNotFound();
        $this->put(route('templates.update', $this->template->ulid), ['roles' => [['name' => 'X']]])->assertNotFound();
        $this->post(route('templates.duplicate', $this->template->ulid))->assertNotFound();
        $this->post(route('templates.archive', $this->template->ulid))->assertNotFound();
        $this->get(route('templates.preview', $this->template->ulid))->assertNotFound();
        $this->get(route('templates.source.show', $this->template->ulid))->assertNotFound();
        $this->post(route('templates.use', $this->template->ulid), [
            'values' => ['nome' => 'Invasor'],
            'participants' => [$roleIds['Locatário'] => ['name' => 'Invasor', 'email' => 'x@example.com']],
        ])->assertNotFound();
        $this->get(route('envelopes.create', ['template' => $this->template->ulid]))->assertNotFound();

        expect(Envelope::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and($this->template->fresh()->status->value)->toBe('active');
    });

    test('galeria e seletor listam só os modelos da organização corrente', function () {
        templateHtml($this->other, $this->otherOwner, '<p>{{cliente}}</p>', [
            ['key' => 'cliente', 'label' => 'Cliente', 'type' => 'text', 'required' => true],
        ], name: 'Modelo da outra empresa', category: 'Vendas');

        actingAsMember($this->otherOwner, $this->other);

        $this->get(route('templates.index'))->assertInertia(fn (Assert $page) => $page
            ->has('templates', 1)
            ->where('templates.0.name', 'Modelo da outra empresa')
            ->where('categories', ['Vendas']));

        $this->getJson(route('templates.picker'))
            ->assertOk()
            ->assertJsonCount(1, 'templates')
            ->assertJsonPath('templates.0.name', 'Modelo da outra empresa');
    });
});
