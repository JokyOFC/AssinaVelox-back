<?php

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Services\Envelopes\Sending\AccessLinks;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial — a revalidação de prazo "a cada acesso" é inalcançável
|--------------------------------------------------------------------------
| `AccessLinks::issue()` grava `expires_at = $envelope->expires_at`
| (app/Services/Envelopes/Sending/AccessLinks.php:68). Ou seja: em produção o link do
| convite vence NO MESMO INSTANTE que o envelope.
|
| `SignerLinkResolver::resolve()` recusa o link em `isUsable()` (linha 57) ANTES de chegar
| na revalidação de prazo (linha 92, `EnvelopeExpiration::revalidate`). Consequências,
| todas no minuto seguinte ao vencimento:
|
|  1. o signatário recebe o 404 genérico "Link inválido" em vez da tela `expired` de
|     ROUTES §3, que existe justamente para dizer que o PRAZO acabou;
|  2. a revalidação de prazo prometida pelo docblock do resolver ("sem confiar no
|     agendador", RECONCILIACAO Q22) nunca roda por esse caminho — o envelope fica
|     `in_progress` até o `envelopes:expire` passar;
|  3. quem JÁ ASSINOU perde o acesso ao próprio comprovante de aceite, porque o link
|     deixou de resolver.
|
| O teste existente `SignerAccessTest::'expira o envelope no acesso…'` passa hoje porque
| a fixture empurra só o `expires_at` do ENVELOPE para o passado e deixa o do link 10 dias
| à frente — um estado que `AccessLinks::issue()` nunca produz.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-expired-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('mostra a tela de prazo encerrado, e não "link inválido", com o link emitido como em produção', function () {
    $ctx = signerEnvelope();

    // Link emitido pelo caminho real: expires_at = prazo do envelope.
    $issued = app(AccessLinks::class)->issue(
        $ctx['recipients']['maria@exemplo.test']->fresh(),
        envelope: $ctx['envelope']->fresh(),
    );

    expect($issued->link->expires_at?->toIso8601String())
        ->toBe($ctx['envelope']->expires_at?->toIso8601String());

    // O prazo passa de verdade.
    $this->travelTo($ctx['envelope']->expires_at->copy()->addMinute());

    $response = $this->get(route('sign.show', ['token' => $issued->token]));

    expect($response->viewData('page')['props']['screen'])->toBe('expired')
        ->and($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Expired)
        ->and($ctx['recipients']['maria@exemplo.test']->fresh()->status)->toBe(RecipientStatus::Expired);
});

it('continua reconhecendo quem já assinou depois que o prazo passa, em vez de dizer "link inválido"', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    $this->travelTo($ctx['envelope']->expires_at->copy()->addMinute());

    $props = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];

    // Dizer "este link não existe" a quem assinou este documento é falso; a tela precisa
    // continuar reconhecendo o aceite registrado.
    expect($props['screen'])->not->toBe('invalid');
});
