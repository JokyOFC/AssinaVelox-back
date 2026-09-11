<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/TemplateHelpers.php';

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

function pdfRoles(): array
{
    return [
        ['ref' => 'a', 'name' => 'Locatário', 'participant_role' => 'signer'],
        ['ref' => 'b', 'name' => 'Locador', 'participant_role' => 'signer'],
    ];
}

test('criar modelo HTML começa com texto, variáveis tipadas e um participante', function () {
    $this->post(route('templates.store'), [
        'name' => 'Declaração de residência',
        'category' => 'Jurídico',
        'source_type' => 'html',
    ])->assertSessionHasNoErrors();

    $template = Template::query()->firstOrFail();
    $version = $template->currentVersion()->with(['variables', 'roles'])->first();

    expect($template->source_type->value)->toBe('html')
        ->and($version->version_number)->toBe(1)
        ->and($version->html_body)->toContain('{{nome_completo}}')
        ->and($version->variables->pluck('type')->map->value->all())->toBe(['text', 'cpf'])
        ->and($version->roles->pluck('name')->all())->toBe(['Signatário'])
        ->and(AuditEvent::query()->where('event_type', AuditEventType::TemplateCreated->value)->first()->payload)
        ->toBe(['template' => $template->ulid, 'source_type' => 'html', 'version' => 1]);
});

test('modelo DOCX/PDF sem arquivo é recusado', function () {
    $this->post(route('templates.store'), ['name' => 'Sem arquivo', 'source_type' => 'pdf'])
        ->assertSessionHasErrors(['file' => 'Envie o arquivo do modelo.']);

    expect(Template::query()->count())->toBe(0);
});

test('criar modelo PDF guarda o arquivo e as páginas (base dos campos)', function () {
    templatesRequirePdftool();

    $pdf = PdfFixtures::twoPagePdf($this->work.'/vistoria.pdf');

    $this->post(route('templates.store'), [
        'name' => 'Termo de vistoria',
        'source_type' => 'pdf',
        'file' => templateUpload($pdf, 'vistoria.pdf'),
    ])->assertSessionHasNoErrors();

    $version = Template::query()->firstOrFail()->currentVersion;

    expect($version->page_count)->toBe(2)
        ->and($version->pages_meta)->toHaveCount(2)
        ->and($version->original_filename)->toBe('vistoria.pdf')
        ->and($version->sha256)->toBe(hash_file('sha256', $pdf))
        ->and(Storage::disk('documents')->exists($version->storage_path))->toBeTrue();
});

test('PDF protegido por senha não vira modelo', function () {
    templatesRequirePdftool();

    $pdf = PdfFixtures::encryptedPdf($this->work.'/senha.pdf', 'segredo');

    $this->post(route('templates.store'), [
        'name' => 'Protegido',
        'source_type' => 'pdf',
        'file' => templateUpload($pdf, 'senha.pdf'),
    ])->assertSessionHasErrors('file');

    expect(Template::query()->count())->toBe(0)
        ->and(Storage::disk('documents')->allFiles())->toBe([]);
});

describe('validação da definição (editor)', function () {
    test('variáveis, papéis e campos inválidos voltam com erro por item', function () {
        templatesRequirePdftool();

        $template = templatePdf($this->organization, $this->owner, $this->work, pdfRoles(), []);

        $this->put(route('templates.update', $template), [
            'variables' => [['key' => 'nome', 'label' => 'Nome', 'type' => 'text']],
            'roles' => pdfRoles(),
        ])->assertSessionHasErrors(['variables' => 'Modelos PDF fixo não têm variáveis no corpo do documento.']);

        $this->put(route('templates.update', $template), [
            'roles' => [
                ['ref' => 'a', 'name' => 'Locatário', 'participant_role' => 'signer'],
                ['ref' => 'b', 'name' => 'locatário', 'participant_role' => 'signer'],
            ],
        ])->assertSessionHasErrors(['roles.1.name' => 'Já existe outro participante com este nome.']);

        $this->put(route('templates.update', $template), [
            'roles' => [['ref' => 'a', 'name' => 'Testemunha', 'participant_role' => 'witness']],
        ])->assertSessionHasErrors('roles.0.participant_role');

        $this->put(route('templates.update', $template), [
            'roles' => pdfRoles(),
            'fields' => [
                ['role_ref' => 'a', 'type' => 'signature', 'page' => 3, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.06],
                ['role_ref' => 'b', 'type' => 'signature', 'page' => 1, 'x' => 0.9, 'y' => 0.1, 'w' => 0.3, 'h' => 0.06],
                ['role_ref' => 'zzz', 'type' => 'text', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.03],
                ['role_ref' => 'a', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'w' => 0.01, 'h' => 0.06],
            ],
        ])->assertSessionHasErrors(['fields.0.page', 'fields.1.width', 'fields.2.role_ref', 'fields.3.width']);

        expect($template->fresh()->currentVersion->version_number)->toBe(2);
    });

    test('aprovador não recebe assinatura e visualizador não recebe campo', function () {
        templatesRequirePdftool();
        templatesEnable($this->organization, participantRoles: true);

        $template = templatePdf($this->organization, $this->owner, $this->work, pdfRoles(), []);

        $this->put(route('templates.update', $template), [
            'roles' => [
                ['ref' => 'a', 'name' => 'Diretoria', 'participant_role' => 'approver'],
                ['ref' => 'b', 'name' => 'Cópia', 'participant_role' => 'viewer'],
            ],
            'fields' => [
                ['role_ref' => 'a', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.06],
                ['role_ref' => 'b', 'type' => 'text', 'page' => 1, 'x' => 0.1, 'y' => 0.3, 'w' => 0.3, 'h' => 0.03],
            ],
        ])->assertSessionHasErrors([
            'fields.0.type' => 'Aprovadores aprovam o conteúdo sem assinar: não podem ter campo de assinatura ou rubrica.',
            'fields.1.role_ref' => 'Visualizadores só recebem cópia do documento e não podem ter campos.',
        ]);
    });

    test('chaves de variável inválidas ou repetidas são recusadas', function () {
        $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
            ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
        ]);

        $this->put(route('templates.update', $template), [
            'html_body' => '<p>{{nome}}</p>',
            'variables' => [
                ['key' => 'nome', 'label' => 'Nome', 'type' => 'text'],
                ['key' => 'nome', 'label' => 'Outro', 'type' => 'text'],
                ['key' => 'Nome Completo', 'label' => 'X', 'type' => 'text'],
                ['key' => 'tipo', 'label' => 'Tipo', 'type' => 'inexistente'],
                ['key' => 'plano', 'label' => 'Plano', 'type' => 'select', 'options' => ['choices' => []]],
            ],
            'roles' => [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
        ])->assertSessionHasErrors(['variables.1.key', 'variables.2.key', 'variables.3.type', 'variables.4.options.choices']);
    });
});

test('duplicar cria um modelo independente, com cópia do arquivo', function () {
    templatesRequirePdftool();

    $template = templatePdf($this->organization, $this->owner, $this->work, pdfRoles(), [
        ['role_ref' => 'a', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
    ]);

    $this->post(route('templates.duplicate', $template))->assertRedirect();

    $copy = Template::query()->where('id', '!=', $template->id)->firstOrFail();
    $original = $template->fresh()->currentVersion;
    $copied = $copy->currentVersion()->with(['roles', 'fields'])->first();

    expect($copy->name)->toBe('Termo de vistoria (cópia)')
        ->and($copied->version_number)->toBe(1)
        ->and($copied->storage_path)->not->toBe($original->storage_path)
        ->and($copied->sha256)->toBe($original->sha256)
        ->and(Storage::disk('documents')->exists($copied->storage_path))->toBeTrue()
        ->and($copied->roles->pluck('name')->all())->toBe(['Locatário', 'Locador'])
        ->and($copied->fields)->toHaveCount(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::TemplateDuplicated->value)->first()->payload)
        ->toBe(['template' => $template->ulid, 'copy' => $copy->ulid]);
});

test('trocar o PDF do modelo cria versão nova e descarta campos que não cabem mais', function () {
    templatesRequirePdftool();

    $template = templatePdf($this->organization, $this->owner, $this->work, pdfRoles(), [
        ['role_ref' => 'a', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
        ['role_ref' => 'b', 'type' => 'signature', 'page' => 2, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
    ]);

    $onePage = PdfFixtures::onePagePdf($this->work.'/uma.pdf');

    $this->post(route('templates.source.update', $template), ['file' => templateUpload($onePage, 'uma.pdf')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Arquivo substituído: versão 3 criada. 1 campo(s) removido(s) por não caberem nas páginas do novo arquivo.');

    $version = $template->fresh()->currentVersion()->with('fields')->first();

    expect($version->page_count)->toBe(1)
        ->and($version->fields)->toHaveCount(1)
        ->and(TemplateVersion::query()->where('template_id', $template->id)->count())->toBe(3);
});

test('arquivar e restaurar; arquivado some do seletor', function () {
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);

    $this->post(route('templates.archive', $template))->assertRedirect();
    expect($template->fresh()->status->value)->toBe('archived');

    $this->getJson(route('templates.picker'))->assertJsonCount(0, 'templates');
    $this->get(route('templates.index'))->assertInertia(fn (Assert $page) => $page->has('templates', 0)->where('counts.archived', 1));
    $this->get(route('templates.index', ['status' => 'archived']))->assertInertia(fn (Assert $page) => $page->has('templates', 1));

    $this->post(route('templates.restore', $template))->assertRedirect();
    expect($template->fresh()->status->value)->toBe('active');
    $this->getJson(route('templates.picker'))->assertJsonCount(1, 'templates');
});

test('seletor busca por nome ou categoria e informa se a pessoa pode usar', function () {
    templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ], name: 'Contrato de locação residencial', category: 'Locação');
    templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ], name: 'Proposta comercial', category: 'Vendas');

    $this->getJson(route('templates.picker', ['q' => 'vendas']))
        ->assertOk()
        ->assertJsonCount(1, 'templates')
        ->assertJsonPath('templates.0.name', 'Proposta comercial')
        ->assertJsonPath('templates.0.source_label', 'Texto (HTML)')
        ->assertJsonPath('can_use', true);

    $this->getJson(route('templates.picker', ['q' => 'locação']))->assertJsonCount(1, 'templates');
    $this->getJson(route('templates.picker'))->assertJsonCount(2, 'templates');
});

test('a página do editor traz definição, versões, opções e limites', function () {
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);

    $this->get(route('templates.edit', $template))->assertInertia(fn (Assert $page) => $page
        ->component('templates/edit')
        ->where('template.id', $template->ulid)
        ->where('template.supports_variables', true)
        ->where('template.supports_fields', false)
        ->where('version.number', 2)
        ->where('definition.html_body', '<p>{{nome}}</p>')
        ->has('definition.variables', 1)
        ->has('definition.roles', 1)
        ->has('versions', 2)
        ->where('versions.0.is_current', true)
        ->has('options.variable_types', 11)
        ->where('options.participant_roles.1.enabled', false)
        ->where('limits.max_roles', 20)
        ->where('can.update', true)
        ->where('can.use', true));
});
