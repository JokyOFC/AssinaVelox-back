<?php

use App\Enums\EnvelopeStatus;

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';
require_once __DIR__.'/../Feature/Verification/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Verificação pública no navegador
|--------------------------------------------------------------------------
| A página que um terceiro — cartório, banco, juiz — abre sem conta nenhuma.
| Duas coisas só existem no navegador e por isso só um teste de navegador as
| prova:
|
| 1. o campo do código em três blocos, que avança de bloco sozinho e navega ao
|    completar os doze caracteres;
| 2. a conferência do arquivo, em que o SHA-256 é calculado pelo WebCrypto na
|    máquina de quem confere. A promessa da tela é que **o arquivo não sai do
|    navegador**; o teste confere o caso certo e o caso adulterado, e o servidor
|    nunca recebe byte nenhum do arquivo.
|
| (`http://127.0.0.1` é contexto seguro no Chromium, então `crypto.subtle` está
| disponível — é a mesma condição de um domínio em HTTPS na produção.)
*/

beforeEach(function () {
    $this->work = browserWorkspace();
    browserDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);

    $envelope = verifiableEnvelope($organization, $owner, EnvelopeStatus::Completed);

    // Código FIXO de propósito: um código sorteado tornaria a causa de uma falha
    // ambígua (defeito da tela ou azar do sorteio?). A cobertura do alfabeto
    // completo — inclusive a letra "L", que já foi descartada pelo sanitizador e
    // deixava ~1 em cada 3 códigos indigitável — está no teste "aceita um código
    // de verificação com a letra L", no fim deste arquivo.
    $envelope->forceFill(['verification_code' => 'ABCD2345WXYZ'])->save();

    $record = finalizeEnvelope($envelope->fresh());

    // O arquivo final que a página vai mandar conferir precisa existir em disco
    // para o navegador poder lê-lo: gera-se o PDF e o resumo registrado passa a
    // ser o do arquivo de verdade.
    $this->finalPath = browserPdf($this->work.DIRECTORY_SEPARATOR.'contrato-final.pdf', 'Contrato de locação residencial');
    $this->finalSha = hash_file('sha256', $this->finalPath);

    $record->forceFill(['final_sha256' => $this->finalSha])->save();

    // Um arquivo alterado depois do registro. Um comentário PDF a mais no fim
    // não muda o que o leitor mostra — e muda o resumo por inteiro, que é
    // exatamente o ponto da conferência.
    $this->tamperedPath = $this->work.DIRECTORY_SEPARATOR.'contrato-adulterado.pdf';
    file_put_contents(
        $this->tamperedPath,
        file_get_contents($this->finalPath)."\n% alterado depois do registro\n",
    );

    $this->envelope = $envelope->fresh();
    $this->record = $record->fresh();
    $this->code = $this->envelope->verification_code;
});

afterEach(function () {
    browserCleanup($this->work ?? null);
});

it('consulta um código pelo formulário de três blocos', function () {
    $page = visit('/verificar');

    $page->assertSee('Verificar documento')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'verificação · formulário');

    // Quatro caracteres no primeiro bloco: o foco passa sozinho para o segundo.
    $page->fill('input[aria-label="Código de verificação, bloco 1 de 3"]', substr($this->code, 0, 4));

    $page->assertScript(
        'document.activeElement.getAttribute("aria-label")',
        'Código de verificação, bloco 2 de 3',
    );

    // Agora o código inteiro, de uma vez, no PRIMEIRO bloco — o campo aceita os
    // doze caracteres (`maxLength={12}`) justamente porque o preenchimento
    // automático entrega tudo junto.
    //
    // Escrever no primeiro bloco é o que torna este teste estável. O componente
    // é controlado: `handleChange(i)` remonta o valor a partir de
    // `value.slice(0, i * 4)`, e o `value` só chega atualizado depois que o
    // React confirma o render do pai. Mover o foco, ao contrário, é imperativo e
    // acontece antes disso — ou seja, ver o foco no bloco 2 NÃO prova que o
    // estado do bloco 1 já subiu. No índice 0 esse prefixo é sempre vazio, então
    // o resultado não depende de o React ter reprocessado coisa nenhuma.
    $page->fill('input[aria-label="Código de verificação, bloco 1 de 3"]', $this->code);

    // Ao completar os doze o componente navega sem clique no botão.
    $page->assertPathIs('/verificar/'.$this->code);

    $page->assertSee('Verificação pública')
        ->assertSee('Contrato de locação residencial')
        ->assertSee('Horizonte Consultoria')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'verificação · resultado');
});

it('recusa um código curto com mensagem em português', function () {
    $page = visit('/verificar');

    $page->fill('input[aria-label="Código de verificação, bloco 1 de 3"]', substr($this->code, 0, 4));

    // Abaixo de doze caracteres o botão fica desabilitado: a tela não navega e
    // não vaza nada sobre o documento.
    $page->assertButtonDisabled('button[type=submit]')
        ->assertPathIs('/verificar')
        ->assertDontSee('Contrato de locação residencial')
        ->assertNoJavascriptErrors();
});

it('confere no navegador o arquivo correto', function () {
    $page = visit('/verificar/'.$this->code);

    $page->assertSee('Conferir o arquivo que você tem em mãos')
        ->assertSee('O arquivo não sai do seu navegador.');

    browserAttachFile($page, "document.querySelector('#file-check-input')", $this->finalPath);

    // A frase inteira, e não só "Confere.": "Não confere." também contém
    // "confere." e a asserção do plugin casa por trecho.
    $page->assertSee('Este é exatamente o arquivo registrado')
        ->assertSee($this->finalSha)
        ->assertDontSee('não corresponde a nenhum dos resumos registrados')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'verificação · arquivo confere');
});

it('acusa um arquivo adulterado', function () {
    $page = visit('/verificar/'.$this->code);

    $page->assertSee('Conferir o arquivo que você tem em mãos');

    browserAttachFile($page, "document.querySelector('#file-check-input')", $this->tamperedPath);

    $page->assertSee('não corresponde a nenhum dos resumos registrados')
        ->assertSee(hash_file('sha256', $this->tamperedPath))
        ->assertDontSee('Este é exatamente o arquivo registrado')
        ->assertNoJavascriptErrors();

    // O resumo do arquivo adulterado não é o registrado — o teste afirma a
    // diferença em vez de confiar só no texto da tela.
    expect(hash_file('sha256', $this->tamperedPath))->not->toBe($this->finalSha);

    browserAssertNoEnglish($page, 'verificação · arquivo adulterado');
});

it('aceita um código de verificação com a letra L', function () {
    // Regressão do defeito corrigido na integração da Fase 1: o sanitizador do
    // formulário descartava o "L", que o gerador do servidor emite, tornando
    // indigitável ~1 em cada 3 códigos. A guarda contra nova divergência entre
    // os dois alfabetos está em tests/Feature/Verification/VerificationContractTest.php.
    $this->envelope->forceFill(['verification_code' => 'ABCDLLLLWXYZ'])->save();
    $this->record->forceFill(['code' => 'ABCDLLLLWXYZ'])->save();

    $page = visit('/verificar');

    $page->fill('input[aria-label="Código de verificação, bloco 1 de 3"]', 'ABCDLLLLWXYZ');

    $page->assertValue('input[aria-label="Código de verificação, bloco 2 de 3"]', 'LLLL')
        ->assertPathIs('/verificar/ABCDLLLLWXYZ');
});

it('não expõe nada sobre um código inexistente', function () {
    $page = visit('/verificar/AAAABBBBCCCC');

    $page->assertDontSee('Contrato de locação residencial')
        ->assertDontSee('Horizonte Consultoria')
        ->assertNoJavascriptErrors();

    browserAssertNoEnglish($page, 'verificação · código inexistente');
});
