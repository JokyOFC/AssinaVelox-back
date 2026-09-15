<?php

use App\Jobs\Documents\ProcessDocumentUpload;
use App\Models\CloudImport;
use App\Models\Document;
use App\Services\CloudImport\CloudProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| Dropbox — link direto do Chooser, com a proteção contra SSRF (docs/fase-3/conectores.md §4.2, §6)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Bus::fake([ProcessDocumentUpload::class]);
    Storage::fake('documents');
    $this->dns = connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    connectorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
    $this->envelope = connectorsDraft($this->organization, $this->owner);
    $this->link = 'https://dl.dropboxusercontent.com/1/view/abc123def/Contrato.pdf';
});

/**
 * @param  array<string, mixed>  $file
 */
function dropboxImport(object $test, array $file): TestResponse
{
    return $test->post(route('cloud_import.dropbox.store', $test->envelope), ['files' => [$file + ['name' => 'Contrato.pdf']]]);
}

test('link direto do Chooser: baixa sem token nenhum e importa pelo mesmo caminho do upload', function () {
    $pdf = connectorsPdfBytes();
    Http::fake(['dl.dropboxusercontent.com/*' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])]);

    dropboxImport($this, ['link' => $this->link, 'id' => 'id:AbC123dEf', 'bytes' => strlen($pdf)])
        ->assertRedirect(route('envelopes.edit', $this->envelope))
        ->assertSessionHas('success');

    $import = CloudImport::withoutOrganizationScope()->sole();
    expect($import->provider)->toBe(CloudProvider::Dropbox)
        ->and($import->external_id)->toBe('id:AbC123dEf')
        ->and($import->sha256)->toBe(hash('sha256', $pdf))
        ->and(Document::withoutOrganizationScope()->where('envelope_id', $this->envelope->getKey())->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === $this->link && ! $request->hasHeader('Authorization'));
    Http::assertSentCount(1);

    // O link (temporário, de acesso ao arquivo) não fica guardado em lugar nenhum.
    expect((string) json_encode(CloudImport::withoutOrganizationScope()->get()->toArray()))->not->toContain('abc123def');
});

test('link fora da lista de hosts do Dropbox é recusado antes de qualquer DNS ou conexão', function (string $link) {
    Http::fake();

    dropboxImport($this, ['link' => $link])
        ->assertRedirect(route('cloud_import.show', $this->envelope))
        ->assertSessionHasErrors('file');

    expect(CloudImport::withoutOrganizationScope()->sole()->rejection_code)->toBe('blocked_url')
        ->and($this->dns->lookups)->toBe([]);

    Http::assertNothingSent();
})->with([
    'outro domínio' => 'https://evil.example.com/Contrato.pdf',
    'sufixo enganoso' => 'https://dl.dropboxusercontent.com.evil.example/Contrato.pdf',
    'host no caminho' => 'https://evil.example/dl.dropboxusercontent.com/Contrato.pdf',
    'IP literal' => 'https://127.0.0.1/Contrato.pdf',
    'IPv6 literal' => 'https://[::1]/Contrato.pdf',
    'IP decimal' => 'https://2130706433/Contrato.pdf',
    'metadados da nuvem' => 'https://169.254.169.254/latest/meta-data',
    'http sem TLS' => 'http://dl.dropboxusercontent.com/Contrato.pdf',
    'outro protocolo' => 'ftp://dl.dropboxusercontent.com/Contrato.pdf',
]);

test('host permitido que resolve para endereço interno é recusado sem conexão', function (array $addresses) {
    $this->dns->set('dl.dropboxusercontent.com', $addresses);
    Http::fake();

    dropboxImport($this, ['link' => $this->link])->assertSessionHasErrors('file');

    expect(CloudImport::withoutOrganizationScope()->sole()->rejection_code)->toBe('blocked_url')
        ->and($this->dns->lookups)->toBe(['dl.dropboxusercontent.com']);

    Http::assertNothingSent();
})->with([
    'rede privada' => [['10.0.0.5']],
    'metadados' => [['169.254.169.254']],
    'loopback' => [['127.0.0.1']],
    'loopback IPv6' => [['::1']],
    'IPv4 mapeado' => [['::ffff:127.0.0.1']],
    'resposta mista' => [[CONNECTORS_PUBLIC_IP, '192.168.0.10']],
    'não resolve' => [[]],
]);

test('usuário e senha na URL são recusados', function () {
    Http::fake();

    dropboxImport($this, ['link' => 'https://user:senha@dl.dropboxusercontent.com/Contrato.pdf'])->assertSessionHasErrors('file');

    expect(CloudImport::withoutOrganizationScope()->sole()->rejection_code)->toBe('blocked_url');
    Http::assertNothingSent();
});

test('redirecionamento não é seguido: vira falha do provedor', function () {
    Http::fake(['dl.dropboxusercontent.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

    dropboxImport($this, ['link' => $this->link])->assertSessionHasErrors('file');

    expect(CloudImport::withoutOrganizationScope()->sole()->rejection_code)->toBe('provider_unavailable');
    Http::assertSentCount(1);
});

test('tamanho declarado acima do limite é recusado sem download; corpo acima do limite também', function () {
    config()->set('assinavelox.upload.max_mb', 1);
    Http::fake(['dl.dropboxusercontent.com/*' => Http::response(connectorsPdfBytes().str_repeat('A', 1100 * 1024), 200)]);

    dropboxImport($this, ['link' => $this->link, 'bytes' => 3 * 1024 * 1024])->assertSessionHasErrors('file');
    Http::assertNothingSent();

    dropboxImport($this, ['link' => $this->link, 'bytes' => 100])->assertSessionHasErrors('file');
    Http::assertSentCount(1);

    expect(CloudImport::withoutOrganizationScope()->pluck('rejection_code')->all())->toBe(['file_too_large', 'file_too_large']);
});

test('arquivo disfarçado vindo do Dropbox é recusado pela inspeção do upload', function () {
    Http::fake(['dl.dropboxusercontent.com/*' => Http::response("MZ\x90\x00".str_repeat("\x00", 64), 200, ['Content-Type' => 'application/pdf'])]);

    dropboxImport($this, ['link' => $this->link])->assertSessionHasErrors('file');

    expect(CloudImport::withoutOrganizationScope()->sole()->rejection_code)->toStartWith('upload_')
        ->and(Document::withoutOrganizationScope()->count())->toBe(0);
});

test('sem o app registrado a importação é recusada com o estado honesto, sem chamada', function () {
    connectorsConfigure(dropbox: false);
    Http::fake();

    dropboxImport($this, ['link' => $this->link])
        ->assertSessionHasErrors(['file' => 'Importação do Dropbox ainda não disponível: aguardando app registrado pelo proprietário.']);

    Http::assertNothingSent();
});

test('com vários documentos ligados, importa mais de um arquivo por vez', function () {
    config()->set('assinavelox.features.multi_document', true);
    $plan = $this->organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => ['multi_document' => true] + (array) $plan->features])->save();

    // Uma resposta NOVA por requisição: o corpo de um Http::response() fixo é um stream lido uma vez.
    Http::fake(['dl.dropboxusercontent.com/*' => fn () => Http::response(connectorsPdfBytes(), 200)]);

    $this->post(route('cloud_import.dropbox.store', $this->envelope), ['files' => [
        ['link' => $this->link, 'name' => 'Contrato.pdf'],
        ['link' => 'https://dl.dropboxusercontent.com/1/view/zzz/Anexo.pdf', 'name' => 'Anexo.pdf'],
    ]])->assertRedirect(route('envelopes.edit', $this->envelope));

    expect(Document::withoutOrganizationScope()->where('envelope_id', $this->envelope->getKey())->count())->toBe(2)
        ->and(CloudImport::withoutOrganizationScope()->where('status', 'completed')->count())->toBe(2);
});
