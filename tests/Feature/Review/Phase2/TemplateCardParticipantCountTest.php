<?php

use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Phase2/Templates/Support/TemplateHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda A (semântica) — cartão do modelo chama todos de "signatários"
|--------------------------------------------------------------------------
| Em Modelos, o cartão mostra `plural(template.roles_count, 'signatário', 'signatários')`
| (resources/js/pages/templates/index.tsx:310 e :317). `roles_count` conta TODOS os papéis
| do modelo (TemplatePresenter::rows) — testemunha, aprovador e visualizador inclusive.
| Um modelo "Locatário + Testemunha + Cópia" aparece como "3 signatários". O seletor do
| wizard (template-picker.tsx:172-175) já diz "3 participantes": as duas telas se contradizem
| e a de Modelos promove testemunha e visualizador a signatário (regra T1 / §2.4).
*/

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization, participantRoles: true);
    actingAsMember($this->owner, $this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

it('não rotula testemunha e visualizador do modelo como "signatários" no cartão', function () {
    templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ], [
        ['ref' => 'a', 'name' => 'Locatário', 'participant_role' => 'signer'],
        ['ref' => 'b', 'name' => 'Testemunha', 'participant_role' => 'witness'],
        ['ref' => 'c', 'name' => 'Cópia', 'participant_role' => 'viewer'],
    ]);

    $props = $this->get(route('templates.index'))->assertOk()->viewData('page')['props'];

    // O número que o cartão pluraliza inclui os três papéis.
    expect($props['templates'][0]['roles_count'])->toBe(3);

    $source = (string) file_get_contents(base_path('resources/js/pages/templates/index.tsx'));

    expect(preg_match("/plural\\(\\s*template\\.roles_count,\\s*'signatário'/u", $source))->toBe(
        0,
        'templates/index.tsx rotula roles_count (todos os papéis) como "signatário(s)": o modelo acima aparece como "3 signatários".'
    );
});
