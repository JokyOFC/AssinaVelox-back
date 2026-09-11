<?php

use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\PublicFormSubmission;
use App\Services\PublicForms\PublicFormManager;
use App\Services\PublicForms\PublicFormSchema;
use App\Services\Templates\TemplateDocumentRenderer;
use App\Services\Templates\TemplateManager;
use App\Services\Templates\VariableValues;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/PublicFormHelpers.php';

/*
| Dados do público são não confiáveis (T6; docs/fase-2/formulario-publico.md §6): Blade,
| PHP, script, fórmula de planilha e marcadores do próprio modelo aparecem LITERAIS no
| documento. O preenchimento é o do motor restrito dos modelos (onda A), sem alteração.
*/

const HOSTILE_VALUE = '<script>alert(1)</script> {{ 7*7 }} {!! $x !!} @php system(id) @endphp =HYPERLINK(1) ${cpf}';

beforeEach(function () {
    Notification::fake();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    publicFormsEnable($this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('DOCX gerado pelo formulário: o valor hostil entra escapado e literal, e nada é reexpandido', function () {
    templatesRequirePdftool();
    config()->set('pdftool.libreoffice.binary', PdfFixtures::fakeSoffice());
    config()->set('pdftool.libreoffice.env', ['FAKE_SOFFICE_PDF' => PdfFixtures::fakeConvertedPdf()]);

    $path = templateDocxFile($this->work.'/ficha.docx', ['Nome: ${nome}', 'CPF: ${cpf}']);
    $manager = app(TemplateManager::class);
    $template = $manager->create($this->organization, $this->owner, ['name' => 'Ficha Word', 'source_type' => 'docx'], templateUpload($path, 'ficha.docx'));
    $manager->update($template, $this->owner, [
        'variables' => [
            ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
            ['key' => 'cpf', 'label' => 'CPF', 'type' => 'cpf', 'required' => true],
        ],
        'roles' => [['ref' => 'r1', 'name' => 'Cliente', 'participant_role' => 'signer']],
    ]);

    $form = publicFormFrom($this->organization, $this->owner, $template->fresh());

    publicFormSubmit($form, ['nome' => HOSTILE_VALUE, 'cpf' => '52998224725'], ['name' => '[Ana](https://evil.example) <b>'])->assertSessionHasNoErrors();
    publicFormConfirm($form, publicFormConfirmationToken())->assertSessionHasNoErrors();

    $document = Document::withoutOrganizationScope()->sole();
    $original = $document->versions()->where('kind', DocumentVersionKind::Original->value)->firstOrFail();
    $local = $this->work.'/gerado.docx';
    file_put_contents($local, Storage::disk('documents')->get($original->storage_path));
    $xml = templateDocxXml($local);

    expect($xml)
        ->toContain('Nome: &lt;script&gt;alert(1)&lt;/script&gt; {{ 7*7 }} {!! $x !!} @php system(id) @endphp =HYPERLINK(1) ${cpf}')
        ->toContain('CPF: 529.982.247-25')
        ->not->toContain('<script>')
        ->not->toContain('Nome: 49')
        // `${cpf}` dentro do VALOR não foi trocado pelo CPF.
        ->and(substr_count($xml, '529.982.247-25'))->toBe(1);

    // O nome digitado vira nome do destinatário como texto (sem interpretar Markdown/HTML).
    expect(Envelope::withoutOrganizationScope()->sole()->recipients()->sole()->name)->toBe('[Ana](https://evil.example) <b>');
});

test('HTML: o corpo preenchido com o valor do público sai escapado (mesmo motor restrito do modelo)', function () {
    $template = publicFormHtmlTemplate($this->organization, $this->owner);
    $form = publicFormFrom($this->organization, $this->owner, $template);

    publicFormSubmit($form, ['nome' => 'Ana', 'obs' => HOSTILE_VALUE, 'valor' => '10,00'])->assertSessionHasNoErrors();

    // Os valores exatos que a confirmação vai usar (payload do envio) passam pelo renderizador
    // do modelo — o mesmo chamado por CreateEnvelopeFromTemplate.
    $values = PublicFormSubmission::withoutOrganizationScope()->sole()->payload['values'];
    $version = $template->fresh()->currentVersion()->with('variables')->first();
    $renderer = app(TemplateDocumentRenderer::class);
    $body = $renderer->htmlBody($version, $renderer->formatted($version, app(VariableValues::class)->validate($version->variables, $values)));

    expect($body)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('{{ 7*7 }}')
        ->toContain('{!! $x !!}')
        ->toContain('@php system(id) @endphp')
        ->toContain('=HYPERLINK(1)')
        ->not->toContain('<script')
        ->not->toContain('49');
});

test('HTML ponta a ponta: o envelope nasce com o documento do motor restrito e sem efeito colateral', function () {
    templatesRequirePdftool();

    $form = publicFormFrom($this->organization, $this->owner, publicFormHtmlTemplate($this->organization, $this->owner));

    publicFormSubmit($form, ['nome' => 'Ana', 'obs' => HOSTILE_VALUE, 'valor' => '10,00'])->assertSessionHasNoErrors();
    publicFormConfirm($form, publicFormConfirmationToken())->assertSessionHasNoErrors();

    $envelope = Envelope::withoutOrganizationScope()->sole();

    expect($envelope->status->isDraftLike())->toBeTrue()
        ->and(Document::withoutOrganizationScope()->where('envelope_id', $envelope->id)->count())->toBe(1)
        ->and($envelope->recipients()->count())->toBe(1);
});

test('valor fixo inválido pelo tipo é recusado ao salvar o formulário', function () {
    $template = publicFormHtmlTemplate($this->organization, $this->owner);
    $form = publicFormFrom($this->organization, $this->owner, $template, activate: false);

    expect(fn () => app(PublicFormManager::class)->update($form, $this->owner, array_merge(
        app(PublicFormSchema::class)->configInput($form),
        ['public_variables' => ['nome', 'obs'], 'fixed_values' => ['valor' => 'muito dinheiro']],
    )))->toThrow(ValidationException::class);
});
