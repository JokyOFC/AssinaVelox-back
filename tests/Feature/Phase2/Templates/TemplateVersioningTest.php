<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\TemplateRole;
use App\Models\TemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/TemplateHelpers.php';

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization);
    actingAsMember($this->owner, $this->organization);

    $this->template = templateHtml($this->organization, $this->owner, '<p>Eu, {{nome}}, aceito.</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

function versioningPayload(string $html, string $roleName = 'Locatário'): array
{
    return [
        'html_body' => $html,
        'variables' => [['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true]],
        'roles' => [['ref' => 'r1', 'name' => $roleName, 'participant_role' => 'signer']],
    ];
}

test('editar o conteúdo cria uma versão nova e preserva a anterior intacta', function () {
    $previous = $this->template->currentVersion;
    $previousHtml = $previous->html_body;

    $this->put(route('templates.update', $this->template), versioningPayload('<p>Eu, {{nome}}, aceito e concordo.</p>', 'Contratante'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Modelo salvo como versão 3. Documentos já gerados não mudam.');

    $current = $this->template->fresh()->currentVersion;

    expect($current->version_number)->toBe(3)
        ->and($current->id)->not->toBe($previous->id)
        ->and($current->html_body)->toBe('<p>Eu, {{nome}}, aceito e concordo.</p>')
        ->and($current->roles->pluck('name')->all())->toBe(['Contratante'])
        ->and($previous->fresh()->html_body)->toBe($previousHtml)
        ->and($previous->fresh()->roles->pluck('name')->all())->toBe(['Locatário']);

    expect(AuditEvent::query()->where('event_type', AuditEventType::TemplateVersionCreated->value)->latest('id')->first()->payload)
        ->toMatchArray(['template' => $this->template->ulid, 'version' => 3, 'previous_version' => 2]);
});

test('salvar sem mudar o conteúdo não cria versão; mudar só o nome também não', function () {
    $this->put(route('templates.update', $this->template), versioningPayload('<p>Eu, {{nome}}, aceito.</p>') + ['name' => 'Novo nome'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Modelo salvo. O conteúdo não mudou, então nenhuma versão nova foi criada.');

    expect($this->template->fresh()->currentVersion->version_number)->toBe(2)
        ->and($this->template->fresh()->name)->toBe('Novo nome')
        ->and(TemplateVersion::query()->count())->toBe(2);
});

test('versões e sua definição são imutáveis no modelo de dados', function () {
    $version = $this->template->currentVersion;

    expect(fn () => $version->forceFill(['html_body' => '<p>x</p>'])->save())->toThrow(LogicException::class)
        ->and(fn () => TemplateRole::query()->firstOrFail()->forceFill(['name' => 'Outro'])->save())->toThrow(LogicException::class);
});

test('editar o modelo depois não altera o envelope já gerado', function () {
    templatesRequirePdftool();

    $roleIds = templateRoleIds($this->template);
    $used = $this->template->fresh()->currentVersion;

    $this->post(route('templates.use', $this->template), [
        'values' => ['nome' => 'Ana Souza'],
        'participants' => [$roleIds['Locatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com']],
    ])->assertSessionHasNoErrors();

    $envelope = Envelope::query()->firstOrFail();
    $document = Document::query()->where('envelope_id', $envelope->id)->firstOrFail();
    $sha = $document->currentVersion->sha256;
    $bytes = Storage::disk('documents')->get($document->currentVersion->storage_path);

    $this->put(route('templates.update', $this->template), versioningPayload('<h1>Texto totalmente novo</h1><p>{{nome}}</p>', 'Contratante'))
        ->assertSessionHasNoErrors();
    $this->post(route('templates.archive', $this->template));

    $envelope->refresh();
    $document->refresh();

    expect($this->template->fresh()->currentVersion->version_number)->toBe(3)
        ->and($document->currentVersion->sha256)->toBe($sha)
        ->and(Storage::disk('documents')->get($document->currentVersion->storage_path))->toBe($bytes)
        ->and($envelope->recipients()->first()->role_label)->toBe('Locatário')
        ->and($envelope->title)->toBe('Contrato de locação')
        ->and(DB::table('template_usages')->where('envelope_id', $envelope->id)->value('template_version_id'))->toBe($used->id);
});
