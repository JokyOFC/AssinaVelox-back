<?php

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\SigningOrder;
use App\Models\Envelope;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — o último signatário é informado de algo que já aconteceu
|--------------------------------------------------------------------------
| `components/sign/receipt-card.tsx` decide o texto só por `completed`: quando o
| envelope ainda não está `completed`, diz "Você receberá o arquivo final quando
| todos os participantes concluírem." e o título é "Você já assinou".
|
| Quem assina por último cai exatamente aí: o envelope passa a `finalizing`,
| `receipt.pending_others` é 0 (a linha "Aguardando N signatário" some) e mesmo
| assim a frase promete o arquivo "quando todos os participantes concluírem" —
| todos já concluíram. A tela `already_signed_pending_others` de ROUTES §3.4
| existe para "Aguardando {n} signatário(s)"; com n = 0 ela não descreve o
| estado.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-receipt-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('não mostra a tela "aguardando os outros" para quem assinou por último', function () {
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test', 'fields' => [FieldType::Signature]],
    ], SigningOrder::Parallel);

    foreach (['maria@exemplo.test', 'carlos@exemplo.test'] as $email) {
        $token = $ctx['tokens'][$email];
        $props = authenticateSigner($this, $token);

        $this->post(route('sign.complete', ['token' => $token]), [
            'authorization' => $props['authorization']['token'],
            'consent' => true,
            'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
            'fields' => [],
        ])->assertRedirect();
    }

    /** @var Envelope $envelope */
    $envelope = Envelope::withoutOrganizationScope()->findOrFail($ctx['envelope']->getKey());
    expect($envelope->status)->not->toBe(EnvelopeStatus::InProgress);

    $props = $this->get(route('sign.show', ['token' => $ctx['tokens']['carlos@exemplo.test']]))
        ->assertOk()
        ->viewData('page')['props'];

    // Ninguém está pendente: a tela não pode ser a de "aguardando os outros",
    // que é a que produz a frase "quando todos os participantes concluírem".
    expect($props['receipt']['pending_others'])->toBe(0)
        ->and($props['screen'])->not->toBe('already_signed_pending_others');
});
