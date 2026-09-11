<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\SigningField;
use App\Models\TemplateField;
use Illuminate\Support\Facades\DB;
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

function vistoriaTemplate(object $test)
{
    return templatePdf($test->organization, $test->owner, $test->work, [
        ['ref' => 'locatario', 'name' => 'Locatário', 'participant_role' => 'signer'],
        ['ref' => 'locador', 'name' => 'Locador', 'participant_role' => 'signer'],
    ], [
        ['role_ref' => 'locatario', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
        ['role_ref' => 'locatario', 'type' => 'text', 'page' => 1, 'x' => 0.1, 'y' => 0.5, 'w' => 0.4, 'h' => 0.03, 'label' => 'Observações', 'required' => false],
        ['role_ref' => 'locador', 'type' => 'signature', 'page' => 2, 'x' => 0.55, 'y' => 0.8, 'w' => 0.3, 'h' => 0.06],
        ['role_ref' => 'locador', 'type' => 'date', 'page' => 2, 'x' => 0.55, 'y' => 0.88, 'w' => 0.2, 'h' => 0.03],
    ]);
}

test('GET envelopes.create?template mostra o formulário com variáveis e papéis', function () {
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}} {{valor}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
        ['key' => 'valor', 'label' => 'Valor do aluguel', 'type' => 'currency', 'required' => false, 'default_value' => '1.500,00'],
    ]);

    $this->get(route('envelopes.create', ['template' => $template->ulid]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('templates/use')
            ->where('template.id', $template->ulid)
            ->where('template.version', 2)
            ->where('defaults.title', 'Contrato de locação')
            ->has('variables', 2)
            ->where('variables.1.key', 'valor')
            ->where('variables.1.type', 'currency')
            ->where('variables.1.default_value', '1.500,00')
            ->has('roles', 1)
            ->where('roles.0.name', 'Locatário')
            ->where('roles.0.participant_role', 'signer'));

    expect(Envelope::query()->count())->toBe(0);
});

test('modelo PDF: envelope em rascunho com destinatários por papel e campos nas posições do modelo', function () {
    templatesRequirePdftool();

    $template = vistoriaTemplate($this);
    $version = $template->currentVersion()->with('fields.role')->first();
    $roleIds = templateRoleIds($template);

    $response = $this->post(route('templates.use', $template), [
        'title' => 'Vistoria — Apto 101',
        'participants' => [
            $roleIds['Locatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com'],
            $roleIds['Locador'] => ['name' => 'Bruno Lima', 'email' => 'BRUNO@example.com'],
        ],
    ]);

    $envelope = Envelope::query()->firstOrFail();
    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 4]));

    expect($envelope->title)->toBe('Vistoria — Apto 101')
        ->and($envelope->status)->toBe(EnvelopeStatus::Ready)
        ->and($envelope->signing_order->value)->toBe('sequential');

    $recipients = $envelope->recipients()->get();
    expect($recipients->pluck('name')->all())->toBe(['Ana Souza', 'Bruno Lima'])
        ->and($recipients->pluck('email')->all())->toBe(['ana@example.com', 'bruno@example.com'])
        ->and($recipients->pluck('role_label')->all())->toBe(['Locatário', 'Locador'])
        ->and($recipients->pluck('role')->all())->toBe([RecipientRole::Signer, RecipientRole::Signer])
        ->and($recipients->pluck('order_index')->all())->toBe([1, 2]);

    $document = Document::query()->where('envelope_id', $envelope->id)->firstOrFail();
    $documentVersion = $document->currentVersion;
    expect($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
        ->and($document->page_count)->toBe(2)
        ->and($documentVersion->sha256)->toBe($version->sha256)
        ->and($documentVersion->pages_meta)->toBe($version->pages_meta);

    $fields = SigningField::query()->where('envelope_id', $envelope->id)->orderBy('page')->orderBy('sort_order')->get();
    expect($fields)->toHaveCount(4);

    $byRole = ['Locatário' => $recipients[0]->id, 'Locador' => $recipients[1]->id];

    foreach ($version->fields as $templateField) {
        /** @var TemplateField $templateField */
        $match = $fields->first(fn (SigningField $field) => $field->type === $templateField->type && $field->page === $templateField->page);

        expect($match)->not->toBeNull()
            ->and($match->x)->toBe($templateField->x)
            ->and($match->y)->toBe($templateField->y)
            ->and($match->width)->toBe($templateField->width)
            ->and($match->height)->toBe($templateField->height)
            ->and($match->recipient_id)->toBe($byRole[$templateField->role->name])
            ->and($match->document_version_id)->toBe($documentVersion->id);
    }

    expect($fields->firstWhere('type', FieldType::Text)->label)->toBe('Observações')
        ->and($fields->firstWhere('type', FieldType::Text)->required)->toBeFalse();

    $usage = DB::table('template_usages')->where('envelope_id', $envelope->id)->first();
    expect($usage->template_version_id)->toBe($version->id)
        ->and($usage->template_id)->toBe($template->id);

    $used = AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', AuditEventType::TemplateUsed->value)->firstOrFail();
    expect($used->payload)->toMatchArray(['template' => $template->ulid, 'version' => 2, 'participants' => 2])
        ->and(json_encode($used->payload))->not->toContain('ana@example.com');
});

test('modelo HTML: documento gerado pelo DOMPDF segue o pipeline e o wizard pede os campos', function () {
    templatesRequirePdftool();

    $template = templateHtml($this->organization, $this->owner, '<h1>Contrato</h1><p>Locatário: {{nome}}, CPF {{cpf}}. Aluguel de {{valor}} a partir de {{inicio}}.</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
        ['key' => 'cpf', 'label' => 'CPF', 'type' => 'cpf', 'required' => true],
        ['key' => 'valor', 'label' => 'Valor', 'type' => 'currency', 'required' => true],
        ['key' => 'inicio', 'label' => 'Início', 'type' => 'date', 'required' => true],
    ]);
    $roleIds = templateRoleIds($template);

    $this->post(route('templates.use', $template), [
        'values' => ['nome' => 'Ana Souza', 'cpf' => '52998224725', 'valor' => '2.350,00', 'inicio' => '2026-10-01'],
        'participants' => [$roleIds['Locatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $envelope = Envelope::query()->firstOrFail();
    $document = Document::query()->where('envelope_id', $envelope->id)->firstOrFail();

    expect($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
        ->and($document->original_filename)->toBe('Contrato de locação.pdf')
        ->and($envelope->recipients()->count())->toBe(1)
        ->and(SigningField::query()->where('envelope_id', $envelope->id)->count())->toBe(0)
        // Sem campos o envelope não fica `ready`: continua em preparo (draft-like).
        ->and($envelope->fresh()->status->isDraftLike())->toBeTrue()
        ->and($envelope->fresh()->status)->not->toBe(EnvelopeStatus::Ready);

    // O wizard rebaixa o passo 4 pedido para o 3 (faltam os campos).
    $this->get(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 4]))
        ->assertInertia(fn (Assert $page) => $page->component('envelopes/wizard')->where('step', 3));
});

test('modelo arquivado não pode ser usado', function () {
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);
    $roleIds = templateRoleIds($template);

    $this->post(route('templates.archive', $template))->assertRedirect();

    $this->get(route('envelopes.create', ['template' => $template->ulid]))
        ->assertRedirect(route('templates.index'))
        ->assertSessionHas('error');

    $this->post(route('templates.use', $template), [
        'values' => ['nome' => 'Ana'],
        'participants' => [$roleIds['Locatário'] => ['name' => 'Ana', 'email' => 'ana@example.com']],
    ])->assertForbidden();

    expect(Envelope::query()->count())->toBe(0);
});

test('testemunha no modelo exige a flag participant_roles também na hora de usar', function () {
    templatesEnable($this->organization, participantRoles: true);

    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ], [
        ['ref' => 'a', 'name' => 'Contratante', 'participant_role' => 'signer'],
        ['ref' => 'b', 'name' => 'Testemunha 1', 'participant_role' => 'witness'],
    ]);
    $roleIds = templateRoleIds($template);

    templatesEnable($this->organization, participantRoles: false);

    $this->post(route('templates.use', $template), [
        'values' => ['nome' => 'Ana'],
        'participants' => [
            $roleIds['Contratante'] => ['name' => 'Ana', 'email' => 'ana@example.com'],
            $roleIds['Testemunha 1'] => ['name' => 'Caio', 'email' => 'caio@example.com'],
        ],
    ])->assertSessionHasErrors('template');

    expect(Envelope::query()->count())->toBe(0);
});
