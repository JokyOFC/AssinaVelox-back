<?php

use App\Enums\AuthMethod;
use App\Enums\SigningSessionStatus;
use App\Models\RecipientPin;
use App\Models\SigningSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../Phase2/Channels/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — PIN: bloqueio conferido fora do lock
|--------------------------------------------------------------------------
| `SenderPins::verify()` (app/Services/Signing/Channels/SenderPins.php) confere
| `isBlocked()` e `isLocked()` na linha lida SEM lock (linhas 237–258). Dentro da
| transação, a linha é relida com `lockForUpdate()` (linha 280) e o PIN é testado direto
| (linha 282) — o estado de bloqueio da linha travada NUNCA é conferido de novo.
|
| Consequência: N requisições simultâneas passam todas pela checagem externa enquanto o
| PIN ainda está livre e, na fila do lock, cada uma testa um PIN. As que chegam depois do
| 5.º erro (bloqueio temporário) ou do 15.º (bloqueio definitivo) continuam testando — e a
| que acerta autentica e ZERA os contadores, mesmo com `blocked_at` gravado. O teto de
| tentativas por participante, que é o que protege um PIN de 4 dígitos, vira só um freio
| por IP (`throttle:10,10`), contornável trocando de IP.
|
| O teste reproduz a corrida de forma determinística: entre a leitura externa e o lock, uma
| requisição "concorrente" bloqueia o PIN (é o que o 15.º erro dela faria). O PIN certo
| que chega junto deveria ser recusado; hoje autentica.
*/

const REVIEW_2B_PIN = '48291573';

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-2b-pin-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->emailCodes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('o PIN bloqueado por uma tentativa concorrente não autentica quem chegou junto', function (string $state) {
    $ctx = channelsSignerContext(AuthMethod::EmailOtp, null, REVIEW_2B_PIN);
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);

    // Código do canal confirmado: a etapa do PIN está aberta neste navegador.
    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();
    $codes = $this->emailCodes;
    $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $codes[count($codes) - 1]])
        ->assertSessionHasNoErrors();

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::PendingAuth);

    // Requisição concorrente: logo depois da leitura SEM lock desta tentativa, outra
    // tentativa (errada) esgota o limite e bloqueia o PIN no banco.
    $raced = false;
    RecipientPin::retrieved(function (RecipientPin $pin) use (&$raced, $state): void {
        if ($raced) {
            return;
        }

        $raced = true;

        DB::table('recipient_pins')->where('id', $pin->id)->update($state === 'blocked'
            ? ['blocked_at' => Carbon::now(), 'lockouts' => 3, 'failed_attempts' => 0, 'locked_until' => null]
            : ['locked_until' => Carbon::now()->addMinutes(15), 'lockouts' => 1, 'failed_attempts' => 0]);
    });

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => REVIEW_2B_PIN]);

    expect($raced)->toBeTrue();

    // O PIN estava bloqueado quando esta tentativa chegou ao lock: ela não pode autenticar.
    expect(SigningSession::query()->where('status', SigningSessionStatus::Authenticated->value)->count())->toBe(0);

    // E o bloqueio gravado pela concorrente continua de pé (hoje o acerto zera `lockouts`).
    $record = RecipientPin::query()->sole();
    expect($state === 'blocked' ? $record->blocked_at : $record->locked_until)->not->toBeNull()
        ->and($record->lockouts)->toBeGreaterThan(0);
})->with(['bloqueio definitivo' => 'blocked', 'bloqueio temporário' => 'locked']);
