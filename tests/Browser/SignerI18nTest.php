<?php

use App\Enums\FieldType;
use App\Models\SignatureAcceptance;

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';
require_once __DIR__.'/../Feature/Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Feature/Phase3/I18n/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — página pública em inglês no navegador (docs/fase-3/multilingue.md §9)
|--------------------------------------------------------------------------
| Com a flag `multilingual` ligada e o participante em inglês, percorre identificação, código,
| assinatura e comprovante e procura PALAVRAS-SENTINELA em português no texto visível. Uma
| chave esquecida, um componente sem tradução ou uma mensagem do servidor fora do catálogo
| aparecem aqui. Os dados do remetente (nome da organização, título) são escolhidos para não
| colidir com as sentinelas.
*/

/** Trechos da interface em PT-BR que não podem aparecer na página em inglês. */
const I18N_PT_SENTINELS = [
    'Confirme sua identidade', 'Receber código', 'Enviaremos', 'Enviamos', 'Conexão protegida',
    'Trilha de auditoria', 'Aviso de privacidade', 'Termos de uso', 'Olá,', 'você', 'Código confirmado',
    'Sua assinatura', 'Desenhar', 'Digitar', 'Recusar', 'Carregando', 'Página ', 'Comprovante',
    'Relatório de evidências', 'Aceite registrado', 'Baixar', 'Ampliar', 'Próximo campo',
    'Tradução de cortesia', 'aceite eletrônico', 'Declaração', 'Este documento', 'assinatura',
];

function browserAssertNoPortuguese(object $page, string $screen): void
{
    $text = (string) $page->script('document.body.innerText');

    expect(mb_strlen(trim($text)))->toBeGreaterThan(40, sprintf('A tela [%s] não tem texto suficiente.', $screen));

    foreach (I18N_PT_SENTINELS as $needle) {
        expect(str_contains($text, $needle))->toBeFalse(
            sprintf('A tela [%s] em inglês mostra português: "%s".', $screen, $needle),
        );
    }
}

beforeEach(function () {
    $this->work = browserWorkspace();
    browserDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    $this->notifications = browserCaptureNotifications();
});

afterEach(function () {
    browserCleanup($this->work ?? null);
});

it('mostra a página pública inteira em inglês, do código ao comprovante', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Northwind Realty']);

    $ctx = signerEnvelope(
        [['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]]],
        envelopeAttributes: ['title' => 'Lease agreement'],
        organization: $organization,
        owner: $owner,
    );

    i18nEnableFlag($ctx['organization']);

    $recipient = $ctx['recipients']['maria@exemplo.test'];
    $recipient->forceFill(['locale' => 'en'])->save();
    $token = $ctx['tokens']['maria@exemplo.test'];

    $page = visit('/assinar/'.$token);

    $page->assertSee('Confirm your identity to sign')
        ->assertSee('Protected connection (TLS)')
        ->assertNoJavascriptErrors();

    browserAssertNoPortuguese($page, 'identify · en');

    $page->click('Get code by email');

    $codes = $this->notifications['codes'];

    browserWaitFor(fn () => count($codes) > 0, 'o código por e-mail ser enviado');

    $page->type('input[aria-label$="received by email"]', $codes[count($codes) - 1]);

    $page->assertSee('Your signature')
        ->assertSee('Code confirmed')
        ->assertSee('Courtesy translation')
        ->assertNoJavascriptErrors();

    browserAssertNoPortuguese($page, 'sign · en');

    browserDrawOnCanvas($page, "document.querySelector('canvas[aria-label=\"Pad to draw the signature\"]')");

    $page->assertSee('Ready');

    $page->click('button[role=checkbox]');
    $page->assertButtonEnabled('Sign document');
    $page->click('Sign document');

    $acceptance = browserWaitFor(
        fn () => SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $recipient->id)->first(),
        'o aceite eletrônico ser registrado',
    );

    // O que fica registrado é o texto de referência, com o idioma exibido ao lado.
    expect($acceptance->display_locale)->toBe('en')
        ->and($acceptance->consent_statement)->toStartWith('Declaração de aceite eletrônico');

    $page->assertSee('Acceptance recorded.')
        ->assertSee('Receipt')
        ->assertNoJavascriptErrors();

    browserAssertNoPortuguese($page, 'receipt · en');
});

it('troca o idioma de exibição pelo seletor do cabeçalho', function () {
    $ctx = signerEnvelope(
        [['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]]],
        envelopeAttributes: ['title' => 'Lease agreement'],
    );

    i18nEnableFlag($ctx['organization']);
    $ctx['recipients']['maria@exemplo.test']->forceFill(['locale' => 'en'])->save();

    $page = visit('/assinar/'.$ctx['tokens']['maria@exemplo.test']);

    $page->assertSee('Confirm your identity to sign');

    $page->select('[data-testid="signer-language"]', 'es');

    $page->assertSee('Confirme su identidad para firmar')
        ->assertSee('Recibir código por correo electrónico')
        ->assertNoJavascriptErrors();
});
