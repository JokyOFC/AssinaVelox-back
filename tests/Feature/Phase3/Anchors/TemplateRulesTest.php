<?php

use App\Enums\EnvelopeStatus;
use App\Models\AnchorScan;
use App\Models\FieldAnchorRule;
use App\Models\FieldSuggestion;
use App\Models\Recipient;
use App\Models\Template;
use App\Services\Templates\CreateEnvelopeFromTemplate;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/AnchorHelpers.php';
require_once __DIR__.'/../../Phase2/Templates/Support/TemplateHelpers.php';

/*
| Regras de âncora por modelo: CRUD na aba "Âncoras", teste contra o PDF do modelo e aplicação
| ao gerar um envelope — sempre como SUGESTÃO que o remetente revisa.
*/

beforeEach(function () {
    anchorsRequirePdftool();
    $this->work = templatesWorkspace();
    anchorsIsolatedDisk($this->work);
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization);
    anchorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

function anchorsTemplate(object $test): Template
{
    // PDF fixo de 2 páginas: "Página 1 — Contrato de teste" e "Página 2 — Anexo".
    return templatePdf($test->organization, $test->owner, $test->work, [
        ['ref' => 'locatario', 'name' => 'Locatário', 'participant_role' => 'signer'],
        ['ref' => 'locador', 'name' => 'Locador', 'participant_role' => 'signer'],
    ], [
        ['role_ref' => 'locatario', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
        ['role_ref' => 'locador', 'type' => 'signature', 'page' => 2, 'x' => 0.55, 'y' => 0.8, 'w' => 0.3, 'h' => 0.06],
    ]);
}

function anchorsRule(array $overrides = []): array
{
    return [
        'pattern' => 'Contrato de teste',
        'field_type' => 'date',
        'role_position' => 2,
        'placement' => 'below',
        'offset_x_pt' => 0,
        'offset_y_pt' => 4,
        'width_pt' => 100,
        'height_pt' => 18,
        'required' => true,
        'occurrence' => 'first',
        ...$overrides,
    ];
}

test('salva, lista e testa as regras do modelo', function () {
    $template = anchorsTemplate($this);

    $this->putJson(route('anchors.template.update', $template), ['rules' => [anchorsRule(), anchorsRule(['pattern' => 'Anexo', 'field_type' => 'initials', 'role_position' => 1, 'placement' => 'right'])]])
        ->assertOk()
        ->assertJsonCount(2, 'rules')
        ->assertJsonPath('rules.0.role_name', 'Locador')
        ->assertJsonPath('rules.1.placement', 'right')
        ->assertJsonPath('can_test', true);

    $this->getJson(route('anchors.template.index', $template))
        ->assertOk()
        ->assertJsonCount(2, 'rules')
        ->assertJsonCount(2, 'roles')
        ->assertJsonPath('roles.1.name', 'Locador');

    $rules = FieldAnchorRule::query()->orderBy('sort_order')->get();
    $result = $this->postJson(route('anchors.template.test', $template))->assertOk()->json();

    expect($result['pages'])->toBe(2)
        ->and($result['markers'])->toBe(0)
        ->and($result['rules'][$rules[0]->ulid])->toBe(1)
        ->and($result['rules'][$rules[1]->ulid])->toBe(1);

    // Salvar as regras não cria versão do modelo.
    expect($template->fresh()->currentVersion->version_number)->toBe($template->currentVersion->version_number);
});

test('validação das regras', function () {
    $template = anchorsTemplate($this);
    $url = route('anchors.template.update', $template);

    $this->putJson($url, ['rules' => [anchorsRule(['role_position' => 3])]])->assertStatus(422)->assertJsonValidationErrors('rules.0.role_position');
    $this->putJson($url, ['rules' => [anchorsRule(['pattern' => 'x'])]])->assertStatus(422)->assertJsonValidationErrors('rules.0.pattern');
    $this->putJson($url, ['rules' => [anchorsRule(['pattern' => '(.*)+'])]])->assertStatus(422)->assertJsonValidationErrors('rules.0.pattern');
    $this->putJson($url, ['rules' => [anchorsRule(['field_type' => 'stamp'])]])->assertStatus(422)->assertJsonValidationErrors('rules.0.field_type');
    $this->putJson($url, ['rules' => [anchorsRule(['offset_x_pt' => 999])]])->assertStatus(422)->assertJsonValidationErrors('rules.0.offset_x_pt');
    $this->putJson($url, ['rules' => array_fill(0, 31, anchorsRule())])->assertStatus(422)->assertJsonValidationErrors('rules');

    // Texto com cara de expressão regular é aceito e procurado LITERALMENTE.
    $this->putJson($url, ['rules' => [anchorsRule(['pattern' => 'Cláusula (a|b).*'])]])->assertOk()->assertJsonPath('rules.0.pattern', 'Cláusula (a|b).*');

    // Lista vazia apaga as regras.
    $this->putJson($url, ['rules' => []])->assertOk()->assertJsonCount(0, 'rules');
});

test('gerar envelope do modelo com regras agenda a busca e cria sugestões pendentes', function () {
    $template = anchorsTemplate($this);
    $this->putJson(route('anchors.template.update', $template), ['rules' => [anchorsRule()]])->assertOk();
    $roles = templateRoleIds($template);

    $envelope = app(CreateEnvelopeFromTemplate::class)->handle($template->fresh(), $this->owner, [
        'title' => 'Contrato gerado',
        'participants' => [
            $roles['Locatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com'],
            $roles['Locador'] => ['name' => 'Bruno Lima', 'email' => 'bruno@example.com'],
        ],
    ]);

    $scan = AnchorScan::query()->where('envelope_id', $envelope->id)->sole();
    expect($scan->trigger)->toBe('template')
        ->and($scan->template_id)->toBe($template->id)
        ->and($scan->status->value)->toBe('done');

    $suggestion = FieldSuggestion::query()->where('envelope_id', $envelope->id)->sole();
    $locador = Recipient::query()->where('envelope_id', $envelope->id)->where('email', 'bruno@example.com')->sole();

    expect($suggestion->source)->toBe('rule')
        ->and($suggestion->type->value)->toBe('date')
        ->and($suggestion->recipient_id)->toBe($locador->id)
        ->and($suggestion->field_anchor_rule_id)->toBe(FieldAnchorRule::query()->sole()->id)
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);

    // Revisada (aqui, descartada), o envelope do modelo fica pronto.
    $this->postJson(route('anchors.suggestions.discard', [$envelope, $suggestion->ulid]))->assertOk();
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('sem regras, ou com a flag desligada, gerar do modelo não agenda nada', function () {
    $template = anchorsTemplate($this);
    $roles = templateRoleIds($template);
    $input = fn (string $suffix): array => [
        'title' => 'Contrato '.$suffix,
        'participants' => [
            $roles['Locatário'] => ['name' => 'Ana Souza', 'email' => "ana{$suffix}@example.com"],
            $roles['Locador'] => ['name' => 'Bruno Lima', 'email' => "bruno{$suffix}@example.com"],
        ],
    ];

    $first = app(CreateEnvelopeFromTemplate::class)->handle($template->fresh(), $this->owner, $input('a'));
    expect(AnchorScan::query()->count())->toBe(0)->and($first->fresh()->status)->toBe(EnvelopeStatus::Ready);

    $this->putJson(route('anchors.template.update', $template), ['rules' => [anchorsRule()]])->assertOk();
    config()->set('assinavelox.features.field_anchors', false);

    $second = app(CreateEnvelopeFromTemplate::class)->handle($template->fresh(), $this->owner, $input('b'));
    expect(AnchorScan::query()->count())->toBe(0)->and($second->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('regras de outro modelo, de outra organização, ficam invisíveis', function () {
    $template = anchorsTemplate($this);
    ['organization' => $other, 'owner' => $stranger] = createOrganizationWithOwner();
    templatesEnable($other);
    anchorsEnable($other);
    actingAsMember($stranger, $other);

    $this->getJson(route('anchors.template.index', $template))->assertNotFound();
    $this->putJson(route('anchors.template.update', $template), ['rules' => [anchorsRule()]])->assertNotFound();
    $this->postJson(route('anchors.template.test', $template))->assertNotFound();

    expect(FieldAnchorRule::query()->withoutGlobalScopes()->count())->toBe(0);
});
