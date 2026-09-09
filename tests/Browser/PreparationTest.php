<?php

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Preparação documental no navegador (wizard de 4 passos)
|--------------------------------------------------------------------------
| Percorre o wizard como uma pessoa percorre: cria a solicitação, espera o
| pipeline documental terminar, cadastra dois signatários, insere um campo de
| assinatura, confere que a posição sobrevive a um recarregamento e envia.
|
| O que ele acrescenta ao `tests/Feature/EndToEnd/PreparationAndSigningTest`:
| aquele prova o contrato HTTP; este prova que o React monta, que o autosave
| dispara sozinho e que a geometria que o cliente calcula é a que o servidor
| grava.
|
| DUAS ETAPAS NÃO PASSAM PELO NAVEGADOR, por limitação medida do
| `pestphp/pest-plugin-browser` 4.3.1 — cada uma tem um teste `skip` no fim
| deste arquivo explicando o porquê:
|
| 1. o upload do arquivo (multipart), e
| 2. qualquer interação sobre a página renderizada pelo PDF.js.
*/

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->work = browserWorkspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    browserDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);
    $owner->forceFill(['password' => bcrypt(browserPassword())])->save();

    $this->organization = $organization;
    $this->owner = $owner->fresh();
    $this->notifications = browserCaptureNotifications();

    $this->pdfPath = browserPdf(
        $this->work.DIRECTORY_SEPARATOR.'contrato-de-locacao.pdf',
        'Contrato de locação',
    );
});

afterEach(function () {
    browserCleanup($this->work ?? null);
});

/**
 * Envia o arquivo pela rota real de upload usando o cliente de teste, e não o
 * navegador — ver o `skip` "envia o arquivo pela dropzone do navegador".
 *
 * Depois do envio, `forgetGuards()` descarta o guard resolvido pelo `actingAs`.
 * Sem isso o usuário ficaria preso no guard singleton e todas as requisições
 * seguintes atendidas pelo servidor embutido — inclusive as do navegador —
 * passariam a ser autenticadas por ele, mascarando qualquer falha de sessão.
 */
function browserUploadDocument(object $test, Envelope $envelope, string $path): void
{
    $test->actingAs($test->owner)
        ->withSession(['current_organization_id' => $test->organization->id])
        ->post(route('envelopes.document.store', ['envelope' => $envelope->ulid]), [
            'file' => new UploadedFile($path, basename($path), 'application/pdf', null, true),
        ])
        ->assertRedirect();

    auth()->forgetGuards();
}

it('prepara e envia uma solicitação inteira pelo navegador', function () {
    $page = visit('/login');
    browserLogin($page, $this->owner->email);
    $page->assertPathIs('/dashboard');

    // --- Passo 1: documento -------------------------------------------------

    $page->navigate('/documentos/nova');
    $page->assertPathContains('/editar');
    $page->assertSee('Nova solicitação de assinatura');

    $envelope = browserWaitFor(
        fn () => Envelope::query()->where('organization_id', $this->organization->id)->latest('id')->first(),
        'o envelope rascunho ser criado',
    );

    browserUploadDocument($this, $envelope, $this->pdfPath);
    browserWaitForDocumentReady($envelope);

    // O wizard já estava aberto; recarregar mostra o arquivo processado — que é
    // o que o polling de `document.status` faz sozinho na vida real.
    $page->navigate('/documentos/'.$envelope->ulid.'/editar?step=1');

    $page->assertSee('contrato-de-locacao.pdf')
        ->assertSee('Pronto')
        ->assertNoJavascriptErrors();

    $page->fill('#envelope-title', 'Contrato de locação — Apto 302');

    browserWaitFor(
        fn () => $envelope->fresh()?->title === 'Contrato de locação — Apto 302',
        'o autosave gravar o título',
    );

    // --- Passo 2: signatários ----------------------------------------------

    $page->navigate('/documentos/'.$envelope->ulid.'/editar?step=2');
    $page->assertSee('Ordem de assinatura');

    $page->click('Adicionar signatário');
    $page->fill('input[placeholder="Nome do signatário"] >> nth=0', 'Ana Beatriz Rocha');
    $page->fill('input[placeholder="email@exemplo.com"] >> nth=0', 'ana.rocha@exemplo.test');

    $page->click('Adicionar signatário');
    $page->fill('input[placeholder="Nome do signatário"] >> nth=1', 'Bruno Carvalho Lima');
    $page->fill('input[placeholder="email@exemplo.com"] >> nth=1', 'bruno.lima@exemplo.test');

    // O autosave tem debounce: a asserção é sobre o efeito no banco, não sobre
    // o tempo decorrido.
    browserWaitFor(
        fn () => Recipient::query()->where('envelope_id', $envelope->id)->count() === 2,
        'os dois signatários serem gravados',
    );

    expect(Recipient::query()->where('envelope_id', $envelope->id)->orderBy('order')->pluck('email')->all())
        ->toBe(['ana.rocha@exemplo.test', 'bruno.lima@exemplo.test']);

    $page->assertNoJavascriptErrors();
    browserAssertNoEnglish($page, 'wizard · signatários');

    // --- Passo 3: campos ----------------------------------------------------

    $page->navigate('/documentos/'.$envelope->ulid.'/editar?step=3');
    $page->assertSee('Adicionar campo para');

    // Escolhe o signatário (a paleta fica desabilitada sem um ativo) e insere o
    // campo de assinatura pelo clique — "inserir no centro" da página.
    $page->click('Ana Beatriz Rocha');
    $page->click('Assinatura');

    $field = browserWaitFor(
        fn () => SigningField::query()
            ->where('envelope_id', $envelope->id)
            ->where('type', 'signature')
            ->first(),
        'o campo de assinatura ser gravado',
    );

    // O cliente calculou o retângulo a partir de `DEFAULT_FIELD_SIZE` e do
    // tamanho da página em pontos que veio do servidor: o campo cai centrado.
    expect((float) $field->x + (float) $field->width / 2)->toBeGreaterThan(0.45)->toBeLessThan(0.55)
        ->and((float) $field->y + (float) $field->height / 2)->toBeGreaterThan(0.45)->toBeLessThan(0.55)
        ->and($field->page)->toBe(1)
        ->and($field->recipient->email)->toBe('ana.rocha@exemplo.test');

    $page->assertSee('Campos inseridos')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'wizard · campos');

    // --- A posição sobrevive a um recarregamento ----------------------------

    $x = (float) $field->x;
    $y = (float) $field->y;

    $page->navigate('/documentos/'.$envelope->ulid.'/editar?step=3');
    $page->assertSee('Adicionar campo para');

    $reloaded = SigningField::query()->findOrFail($field->id);

    expect((float) $reloaded->x)->toBe($x)
        ->and((float) $reloaded->y)->toBe($y);

    // O segundo signatário ainda não tem campo: a tela avisa antes do envio.
    $page->assertSee('Bruno');

    // --- Passo 4: revisão e envio ------------------------------------------

    // Bruno também precisa assinar para o envelope poder sair.
    $page->click('Bruno Carvalho Lima');
    $page->click('Assinatura');

    browserWaitFor(
        fn () => SigningField::query()->where('envelope_id', $envelope->id)->count() === 2,
        'o campo do segundo signatário ser gravado',
    );

    $page->navigate('/documentos/'.$envelope->ulid.'/editar?step=4');
    $page->assertSee('Enviar para assinatura');

    $page->click('Enviar para assinatura');

    browserWaitFor(
        fn () => $envelope->fresh()?->status === EnvelopeStatus::InProgress,
        'o envelope entrar em andamento',
    );

    $sent = $envelope->fresh();

    expect($sent->sent_document_version_id)->not->toBeNull()
        ->and($sent->verification_code)->not->toBeNull()
        ->and($sent->title)->toBe('Contrato de locação — Apto 302');

    // O convite do primeiro signatário saiu (a ordem sequencial é o padrão).
    expect($this->notifications['invites'])->toHaveKey('ana.rocha@exemplo.test');

    $page->assertNoJavascriptErrors();
});

it('não deixa pular passos enquanto falta o documento', function () {
    $page = visit('/login');
    browserLogin($page, $this->owner->email);

    $page->navigate('/documentos/nova');
    $page->assertSee('Nova solicitação de assinatura');

    $envelope = browserWaitFor(
        fn () => Envelope::query()->where('organization_id', $this->organization->id)->latest('id')->first(),
        'o envelope rascunho ser criado',
    );

    // Pedir o passo 4 na barra de endereços não adianta: `wizardStep()` rebaixa
    // para o primeiro passo incompleto e a tela volta a pedir o arquivo.
    $page->navigate('/documentos/'.$envelope->ulid.'/editar?step=4');

    $page->assertSee('Informações do documento')
        ->assertPresent('#envelope-title')
        ->assertNotPresent('.shadow-pdf')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'wizard · passo 1');
});

it('envia o arquivo pela dropzone do navegador', function () {
    // Sem corpo de propósito — ver a mensagem do skip.
})->skip(
    'O `pest-plugin-browser` 4.3.1 não atende requisições multipart. '
    .'`Pest\\Browser\\Drivers\\LaravelHttpServer::handleRequest()` monta a requisição com '
    .'`Request::create(..., [], ...)` — a lista de arquivos é literalmente vazia, com o '
    .'comentário "@TODO files..." — e, na prática, o XHR/`fetch` do navegador com '
    .'`multipart/form-data` nunca é concluído: o teste TRAVA em vez de falhar (medido: um '
    .'`fetch` GET na mesma página responde 200, então não é deadlock geral). O upload é '
    .'exercitado pela rota real em tests/Feature/Documents/DocumentUploadTest.php e, aqui, '
    .'pelo cliente de teste em `browserUploadDocument()`. Reavaliar quando o plugin '
    .'preencher `$request->files`.'
);

it('arrasta um campo de assinatura sobre a página do PDF', function () {
    // Sem corpo de propósito — ver a mensagem do skip.
})->skip(
    'O PDF.js não consegue abrir o documento através do servidor embutido do plugin, então '
    .'a camada de campos (que só monta depois da primeira página rasterizada) nunca existe '
    .'e não há alvo para arrastar. Causa medida: `DocumentStorage::stream()` devolve um '
    .'`StreamedResponse`; para esse tipo `LaravelHttpServer::handleRequest()` captura a '
    .'saída com `ob_start()` e aplica `mb_trim(ob_get_clean())`. `mb_trim` trata bytes '
    .'binários como UTF-8 e DESCARTA sequências inválidas no meio do arquivo (num PDF de '
    .'1.127 bytes gerado pelo dompdf sobram 1.101, com a primeira divergência no offset '
    .'325, sobre o BOM UTF-16 do campo /Producer). O corpo chega corrompido e menor que o '
    .'`Content-Length` declarado, e o navegador aborta com "Failed to fetch". A geometria '
    .'dos campos é coberta por tests/Unit (lib/geometry) e por '
    .'tests/Feature/Envelopes; o posicionamento por arrasto continua sendo verificação '
    .'manual até o plugin parar de passar binário por `mb_trim`.'
);
