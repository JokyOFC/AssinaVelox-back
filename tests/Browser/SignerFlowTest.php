<?php

use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\SignatureKind;
use App\Models\SignatureAcceptance;

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';
require_once __DIR__.'/../Feature/Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Fluxo público do signatário no navegador
|--------------------------------------------------------------------------
| A tela mais importante do produto e a única que uma pessoa sem conta usa.
| Percorre o caminho inteiro: abrir o link do convite, pedir o código, digitá-lo,
| desenhar a assinatura no canvas com eventos de ponteiro, marcar o aceite,
| concluir e ver o comprovante — e o caminho da recusa.
|
| O código por e-mail é lido do evento de envio da notificação: ele não existe em
| lugar nenhum do banco (só o HMAC) e não aparece em log nem na trilha. Como o
| servidor HTTP do plugin roda no mesmo processo do teste, o listener registrado
| aqui dispara dentro da requisição feita pelo navegador.
|
| O documento em si não aparece: o PDF.js não consegue abrir o arquivo através do
| servidor embutido do plugin (ver o `skip` em PreparationTest). Isso não impede
| este fluxo, porque a captura da assinatura, os campos e o aceite ficam na
| coluna lateral — mas é por isso que nenhuma asserção aqui olha para o
| visualizador.
*/

beforeEach(function () {
    $this->work = browserWorkspace();
    browserDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    $this->notifications = browserCaptureNotifications();
});

afterEach(function () {
    browserCleanup($this->work ?? null);
});

/** Envelope enviado com um único signatário e um campo de assinatura. */
function signerScenario(array $overrides = []): array
{
    return signerEnvelope(
        [array_merge([
            'name' => 'Maria Alves Souza',
            'email' => 'maria@exemplo.test',
            'fields' => [FieldType::Signature],
        ], $overrides)],
    );
}

/**
 * Percorre a etapa "Confirmar identidade" pelo navegador e devolve o código
 * usado, para as asserções do teste.
 *
 * @param  object  $page  página aberta em `/assinar/{token}`
 */
function browserConfirmIdentity(object $test, object $page): string
{
    $page->assertSee('Confirme sua identidade para assinar');

    $page->click('Receber código por e-mail');

    $codes = $test->notifications['codes'];

    browserWaitFor(fn () => count($codes) > 0, 'o código por e-mail ser enviado');

    $code = $codes[count($codes) - 1];

    // Digitar tecla a tecla, e não `fill`: o `input-otp` monta o valor a partir
    // dos eventos de teclado e envia sozinho ao completar os seis dígitos.
    $page->type('input[aria-label^="Código de "]', $code);

    return $code;
}

it('assina o documento do começo ao fim', function () {
    $scenario = signerScenario();
    $token = $scenario['tokens']['maria@exemplo.test'];
    $recipient = $scenario['recipients']['maria@exemplo.test'];

    $page = visit('/assinar/'.$token);

    $page->assertSee('Contrato de locação')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'signatário · identidade');

    browserConfirmIdentity($this, $page);

    $page->assertSee('Sua assinatura')
        ->assertSee('Identidade confirmada')
        ->assertNoJavascriptErrors();

    // Sem assinatura e sem aceite o botão continua desabilitado.
    $page->assertButtonDisabled('Assinar documento');

    browserDrawOnCanvas($page, "document.querySelector('canvas[aria-label=\"Quadro para desenhar a assinatura\"]')");

    // A prévia "Pronta" só aparece depois de o traço virar um PNG recortado.
    $page->assertSee('Pronta');

    // O aceite nunca vem marcado — marcá-lo é um ato do signatário, e a caixa
    // do `ConsentBox` é a única da tela neste cenário (o único campo do
    // signatário é a assinatura).
    $page->assertCount('button[role=checkbox]', 1)
        ->assertNotChecked('button[role=checkbox]');

    $page->click('button[role=checkbox]');
    $page->assertChecked('button[role=checkbox]');

    $page->assertButtonEnabled('Assinar documento');
    $page->click('Assinar documento');

    $acceptance = browserWaitFor(
        fn () => SignatureAcceptance::withoutOrganizationScope()
            ->where('recipient_id', $recipient->id)
            ->first(),
        'o aceite eletrônico ser registrado',
    );

    expect($acceptance->consent_statement)->not->toBeEmpty()
        ->and($acceptance->signature_kind)->toBe(SignatureKind::Drawn)
        ->and($acceptance->signature_image_path)->not->toBeNull()
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Signed);

    // Comprovante.
    $page->assertSee('Aceite registrado.')
        ->assertSee('Comprovante')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'signatário · comprovante');
});

it('recusa a assinatura com motivo e mostra a tela de recusa', function () {
    $scenario = signerScenario();
    $token = $scenario['tokens']['maria@exemplo.test'];
    $recipient = $scenario['recipients']['maria@exemplo.test'];

    $page = visit('/assinar/'.$token);

    browserConfirmIdentity($this, $page);

    $page->assertSee('Sua assinatura');

    $page->click('Recusar assinatura');
    $page->assertSee('Recusar a assinatura?');

    // Menos que o mínimo mantém o botão travado.
    $page->fill('#refusal-reason', 'curto');
    $page->assertButtonDisabled('[role="dialog"] >> text="Recusar assinatura"');

    $page->fill('#refusal-reason', 'O valor do aluguel está diferente do que foi combinado.');
    $page->click('[role="dialog"] >> text="Recusar assinatura"');

    browserWaitFor(
        fn () => $recipient->fresh()?->status === RecipientStatus::Refused,
        'a recusa ser registrada',
    );

    $page->assertSee('Assinatura recusada')
        ->assertSee('O valor do aluguel está diferente do que foi combinado.')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'signatário · recusa');
});

it('recusa um código errado com mensagem em português', function () {
    $scenario = signerScenario();
    $token = $scenario['tokens']['maria@exemplo.test'];

    $page = visit('/assinar/'.$token);

    $page->assertSee('Confirme sua identidade para assinar');
    $page->click('Receber código por e-mail');

    $codes = $this->notifications['codes'];
    browserWaitFor(fn () => count($codes) > 0, 'o código por e-mail ser enviado');

    // Um código que não é o enviado (e continua tendo seis dígitos).
    $wrong = $codes[0] === '000000' ? '111111' : '000000';

    $page->type('input[aria-label^="Código de "]', $wrong);

    $page->assertSee('Código inválido ou expirado')
        ->assertDontSee('Sua assinatura')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'signatário · código errado');
});

it('mostra a página pública do signatário utilizável em 375 px', function () {
    $scenario = signerScenario();
    $token = $scenario['tokens']['maria@exemplo.test'];

    $page = visit('/assinar/'.$token);
    $page->resize(375, 812);

    $page->assertSee('Confirme sua identidade para assinar')
        ->assertVisible('button >> text="Receber código por e-mail"');

    // Nada pode transbordar a largura da tela: rolagem horizontal na página
    // pública do signatário é o defeito clássico de responsividade.
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth + 1'))->toBeTrue();

    browserConfirmIdentity($this, $page);

    $page->assertSee('Sua assinatura')
        ->assertVisible('canvas[aria-label="Quadro para desenhar a assinatura"]')
        ->assertVisible('button >> text="Assinar documento"');

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth + 1'))->toBeTrue();

    // O quadro de desenho continua alto e largo o bastante para um traço.
    $box = $page->script(
        "(() => { const c = document.querySelector('canvas[aria-label=\"Quadro para desenhar a assinatura\"]');"
        .' const r = c.getBoundingClientRect(); return { width: Math.round(r.width), height: Math.round(r.height) }; })()'
    );

    expect($box['width'])->toBeGreaterThan(240)
        ->and($box['height'])->toBeGreaterThan(120);

    browserDrawOnCanvas($page, "document.querySelector('canvas[aria-label=\"Quadro para desenhar a assinatura\"]')");
    $page->assertSee('Pronta');

    $page->assertNoJavascriptErrors();
    browserAssertNoEnglish($page, 'signatário · 375 px');
});
