<?php

use App\Enums\FieldType;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final (semântica) — aceite gravado sem que o documento tenha sido entregue
|--------------------------------------------------------------------------
| A declaração que o signatário assina começa assim (docs/juridico/declaracao-de-aceite.md,
| item 1, reproduzida na tela e gravada em `signature_acceptances.consent_statement`):
|
|   "Li integralmente o documento '…', cujo conteúdo apresentado nesta tela corresponde
|    ao resumo SHA-256 …"
|
| Nada no fluxo confere que o documento chegou a ser apresentado:
|
|  - no cliente, `canSubmit` (resources/js/pages/sign/show.tsx:405-410) exige consentimento,
|    campos obrigatórios e imagem de assinatura — nunca o estado do visualizador. Observado
|    no navegador: com o PDF respondendo 404, o painel mostra "O arquivo não está mais
|    disponível." e a coluna da direita continua oferecendo a declaração e o botão
|    "Assinar documento";
|  - no servidor, `sign.complete` não exige nenhuma passagem anterior por `sign.document`
|    nem por `sign.page`;
|  - na trilha, não existe evento de apresentação. `AuditEventType` vai de
|    `invitation.opened` ("Abertura detectada — registra o acesso ao link, não comprova
|    leitura", como a própria página de evidências avisa) direto para `session.started` e
|    `acceptance.recorded`. O dossiê de evidências, portanto, não tem como sustentar a
|    frase "conteúdo apresentado nesta tela": não há registro de que os bytes saíram do
|    servidor para aquela sessão.
|
| Este teste percorre o caminho real (código por e-mail, token de autorização) e aceita
| SEM nunca pedir o documento. Hoje o aceite é gravado e a trilha fica sem qualquer
| evidência de apresentação.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-presentation-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/*
| AJUSTE DE TESTE (revisão final).
|
| O caso original aceitava SEM nenhum GET em `sign.document` e, ao mesmo tempo, exigia que a
| trilha trouxesse um evento de apresentação. As duas coisas não podem valer juntas: se os
| bytes nunca saíram do servidor, um evento dizendo que saíram seria exatamente a afirmação
| falsa que o achado denuncia. E a correção que o próprio achado pede — "exigir esse registro
| no servidor antes de aceitar `sign.complete`" — impede que o aceite daquele caso exista.
|
| A garantia está inteira nos dois casos abaixo: sem apresentação o aceite é RECUSADO; com
| apresentação ele é gravado e a trilha traz `document.presented` para sustentar a frase
| "conteúdo apresentado nesta tela".
*/
it('recusa o aceite quando o documento nunca foi entregue a esta sessão', function () {
    $ctx = signerEnvelope([[
        'name' => 'Maria Alves Souza',
        'email' => 'maria@exemplo.test',
        'fields' => [FieldType::Signature],
    ]]);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSignerOnly($this, $token);

    // Nenhum GET em sign.document: os bytes do PDF nunca saíram do servidor.
    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    expect(SignatureAcceptance::query()->count())->toBe(
        0,
        'Aceite gravado sem que o documento tivesse sido apresentado à sessão.',
    );
});

it('a trilha registra que o documento foi apresentado à sessão que aceitou', function () {
    $ctx = signerEnvelope([[
        'name' => 'Maria Alves Souza',
        'email' => 'maria@exemplo.test',
        'fields' => [FieldType::Signature],
    ]]);

    $token = $ctx['tokens']['maria@exemplo.test'];

    // `authenticateSigner()` faz o que o navegador do signatário faz: carrega a tela e busca
    // o PDF. É esse GET que marca a apresentação.
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect(route('sign.show', ['token' => $token]));

    /** @var SignatureAcceptance $acceptance */
    $acceptance = SignatureAcceptance::query()->sole();

    // O texto gravado afirma leitura integral do conteúdo apresentado…
    expect($acceptance->consent_statement)->toContain('Li integralmente o documento');

    // …e a trilha do envelope agora tem o evento que sustenta a palavra "apresentado".
    $types = AuditEvent::query()
        ->where('envelope_id', $ctx['envelope']->id)
        ->pluck('event_type')
        ->map(fn ($type): string => $type instanceof BackedEnum ? (string) $type->value : (string) $type)
        ->all();

    $presentation = array_values(array_filter(
        $types,
        fn (string $type): bool => str_contains($type, 'document.presented')
            || str_contains($type, 'document.viewed')
            || str_contains($type, 'document.delivered')
    ));

    expect($presentation)->not->toBe(
        [],
        'Aceite gravado sem nenhum evento de apresentação do documento. Trilha: '.implode(', ', $types)
    );

    // E a sessão que autorizou o aceite carrega a marca da entrega.
    expect(SigningSession::query()->whereNotNull('document_presented_at')->count())->toBe(1);
});

it('a página de evidências reconhece que abrir o link não comprova leitura — o controle do achado', function () {
    $evidence = (string) file_get_contents(resource_path('js/pages/envelopes/evidence.tsx'));

    expect($evidence)->toContain('não comprova');
});
