<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final de segurança — a rota que entrega o PDF inteiro sem teto
|--------------------------------------------------------------------------
| `AppServiceProvider::configureDownloadRateLimiting()` declara a regra por escrito:
| "Downloads autorizados e exportações. Cada requisição entrega um arquivo inteiro
| (PDF final, dossiê de evidências, CSV): sem teto, uma sessão legítima roubada vira um
| raspador do acervo da organização."
|
| O mapa `assinavelox.rate_limit_routes` (config/assinavelox.php:381-388) aplica essa
| regra a `envelopes.download`, `envelopes.evidence`, `sign.download`,
| `billing.payments.receipt`, `dashboard.export`, `recipients.export` e
| `admin.organizations.export` — mas NÃO a `envelopes.document.preview`, que
| (EnvelopeDocumentController::preview, linha 113) transmite o PDF completo da versão
| exibível, do disco privado, com o mesmo `DocumentStorage::stream()` dos demais.
|
| Nenhum outro limitador a alcança: o grupo `app` de routes/web.php não declara
| `throttle:` e o skeleton não pendura `throttle:web` na pilha `web`
| (bootstrap/app.php). O resultado é a única rota de arquivo inteiro sem teto nenhum.
|
| `tests/Feature/Hardening/RateLimitsTest.php` verifica que as rotas DO MAPA existem e
| apontam para limitadores válidos — o inverso (uma rota de arquivo fora do mapa) não é
| verificado por nenhum teste.
|
| Os dois testes abaixo falham hoje.
*/

beforeEach(fn () => $this->withoutVite());

it('aplica o limitador de download à rota que transmite o PDF da versão exibível', function () {
    $map = (array) config('assinavelox.rate_limit_routes');

    expect($map)->toHaveKey('envelopes.document.preview')
        ->and($map['envelopes.document.preview'] ?? null)->toBe('download');
});

it('freia a leitura repetida do PDF privado do envelope', function () {
    Storage::fake('documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->ready()->create();
    $document = Document::factory()->forEnvelope($envelope)->create();

    $version = DocumentVersion::factory()->forDocument($document)->create([
        'storage_path' => 'orgs/'.$organization->ulid.'/envelopes/'.$envelope->ulid.'/preview.pdf',
        'size_bytes' => 5,
    ]);

    Storage::disk('documents')->put($version->storage_path, '%PDF-');

    $document->forceFill(['current_version_id' => $version->getKey()])->save();

    actingAsMember($owner, $organization);

    $statuses = [];

    // O teto declarado para arquivo inteiro é 60/min por ator (limitador `download`).
    for ($i = 0; $i < 90; $i++) {
        $statuses[] = $this->get(route('envelopes.document.preview', $envelope))->getStatusCode();
    }

    // Guarda contra asserção vazia: a rota precisa ter servido o PDF antes de frear —
    // um 404 ou 403 logo na primeira requisição faria o `toContain(429)` abaixo passar
    // por engano, já que nenhum 200 teria acontecido.
    expect($statuses[0])->toBe(200)
        ->and(array_count_values($statuses)[200] ?? 0)->toBeGreaterThan(1);

    // E, passado o teto do limitador `download`, precisa recusar.
    expect($statuses)->toContain(429);

    // Nada além de servir e frear: nenhuma outra resposta no meio do caminho.
    expect(array_values(array_unique($statuses)))->toBe([200, 429]);
});
