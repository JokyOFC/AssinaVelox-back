<?php

use App\Enums\RecipientStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase3/Embed/Support/EmbedHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial G-EMBED — status da sessão embutida depois de desfecho pelo e-mail
|--------------------------------------------------------------------------
| `outcome` só é gravado quando o aceite/recusa passa PELO widget. Se a pessoa responde pelo
| link do e-mail enquanto a sessão embutida está aberta, a API continua informando `active` por
| até 30 min — e a sessão segue contando no teto `max_live_per_recipient`. O integrador que
| decide pelo `status` (ex.: reabrir o widget) recebe um estado que não existe mais.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = storage_path('framework/testing/review-embed-status-'.Str::random(8));
    signerDisk($this->work);
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

test('recusa pelo link do e-mail: a sessão embutida aberta deixa de ser informada como "active"', function () {
    $scenario = embedScenario();
    $maria = $scenario['recipient'];
    $open = embedOpen($this, $scenario);

    $token = $scenario['tokens'][$maria->email];
    authenticateSigner($this, $token);
    $this->post(route('sign.refuse', ['token' => $token]), ['reason' => 'Não concordo com a cláusula 3.'])->assertRedirect();

    expect($maria->fresh()->status)->toBe(RecipientStatus::Refused);

    $this->getJson(route('api.v1.envelopes.recipients.embedded_sessions.show', [
        'envelope' => $scenario['envelope']->ulid,
        'recipient' => $maria->ulid,
        'embeddedSession' => $open['id'],
    ]), apiHeaders($scenario['api_token']))
        ->assertOk()
        ->assertJsonPath('data.status', fn (string $status): bool => $status !== 'active');
});
