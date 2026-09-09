<?php

use App\Enums\EnvelopeStatus;
use App\Models\SignatureAcceptance;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial — imagem de assinatura gravada por um aceite que não existe
|--------------------------------------------------------------------------
| `RecordAcceptance::handle()` grava a imagem normalizada no disco em
| `resolveVisual()` (app/Services/Signing/RecordAcceptance.php:102) e só DEPOIS abre a
| transação que revalida, sob lock, se o aceite ainda pode entrar
| (`persist()` → `assertStillSignable()`).
|
| Quando a revalidação recusa — envelope cancelado, prazo vencido ou encerrado por recusa
| de outro participante entre a renderização da tela e o clique — a exceção sobe, nada é
| gravado no banco e o arquivo PNG **permanece no disco para sempre**. Nada o remove: não
| há linha em `signature_acceptances` nem em `signing_field_values` apontando para ele.
|
| Dois problemas, um de cada natureza:
|
|  - **Dado pessoal órfão**: a imagem manuscrita de uma assinatura fica armazenada,
|    associada à pasta do envelope, referente a um aceite que juridicamente não aconteceu
|    e sobre o qual nenhum registro existe — nem para auditar, nem para apagar a pedido.
|  - **Consumo de disco**: com sessão viva e `throttle:10,1`, dez imagens de até 3 MB por
|    minuto entram no disco sem que nenhum aceite seja criado.
|
| A ordem correta é a que o próprio docblock da classe promete para a chamada externa:
| decidir antes, gravar depois — ou remover o arquivo quando a transação recusa.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-orphan-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('não deixa imagem de assinatura no disco quando o aceite é recusado sob lock', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    // Entre a renderização da tela e o clique, o remetente cancela o envelope.
    $ctx['envelope']->forceFill([
        'status' => EnvelopeStatus::Canceled,
        'canceled_at' => now(),
    ])->save();

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri(800, 300)],
    ]);

    $assinaturas = array_values(array_filter(
        Storage::disk('documents')->allFiles(),
        fn (string $path): bool => str_contains($path, '/signatures/'),
    ));

    expect(SignatureAcceptance::query()->count())->toBe(0)
        ->and($assinaturas)->toBe([]);
});
