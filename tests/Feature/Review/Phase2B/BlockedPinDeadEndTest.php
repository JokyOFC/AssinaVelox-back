<?php

use App\Enums\AuthMethod;
use App\Models\RecipientPin;
use App\Services\Signing\Channels\SenderPins;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../Phase2/Channels/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial (onda B, produto) — PIN bloqueado é um beco sem saída
|--------------------------------------------------------------------------
| Depois de várias tentativas erradas o PIN fica bloqueado de vez e a página pública diz
| ao participante (otp-card.tsx:409-415; SenderPins.php:246 e :350):
|   "O PIN foi bloqueado depois de várias tentativas incorretas. Fale com <remetente>:
|    só quem enviou o documento pode definir um PIN novo."
|
| Mas, depois do envio, o remetente NÃO consegue fazer isso:
|   - o detalhe do documento não mostra que o PIN está bloqueado (RecipientResource só
|     devolve `auth_methods` com `sender_pin`, igual com ou sem bloqueio);
|   - o único caminho de edição após o envio (PATCH envelopes.recipients.update,
|     EnvelopeRecipientController::update) aceita apenas nome e e-mail; o `sync`, que
|     define PIN, só roda em rascunho. Nenhuma rota chama SenderPins::set() depois do envio.
| Resultado: participante e remetente ficam presos; a única saída é cancelar e refazer.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-2b-pin-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('o detalhe do documento mostra ao remetente que o PIN do participante foi bloqueado', function () {
    $ctx = channelsSignerContext(AuthMethod::EmailOtp, null, '48291573');
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);
    actingAsMember($ctx['owner'], $ctx['organization']);

    $recipientProps = fn (): array => collect(
        $this->get(route('envelopes.show', $ctx['envelope']))->assertOk()->viewData('page')['props']['recipients']
    )->firstWhere('id', $ctx['recipient']->ulid);

    $before = $recipientProps();

    RecipientPin::query()->sole()->forceFill(['blocked_at' => now(), 'lockouts' => 3])->save();

    $after = $recipientProps();

    expect($after)->not->toEqual(
        $before,
        'Com o PIN bloqueado de vez, o participante é mandado "falar com quem enviou", mas o '
            .'cartão do participante no detalhe do documento é idêntico ao de antes do bloqueio: '
            .'o remetente não tem como saber o que aconteceu.'
    );
});

it('o remetente consegue definir um PIN novo depois do envio, desbloqueando o participante', function () {
    $ctx = channelsSignerContext(AuthMethod::EmailOtp, null, '48291573');
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);
    actingAsMember($ctx['owner'], $ctx['organization']);

    RecipientPin::query()->sole()->forceFill(['blocked_at' => now(), 'lockouts' => 3])->save();

    $this->patch(route('envelopes.recipients.update', [$ctx['envelope'], $ctx['recipient']]), [
        'name' => $ctx['recipient']->name,
        'email' => $ctx['recipient']->email,
        'pin' => '73915284',
    ])->assertRedirect();

    $record = RecipientPin::query()->sole();

    expect($record->blocked_at)->toBeNull(
        'A tela pública promete "só quem enviou o documento pode definir um PIN novo", mas o '
            .'único caminho de edição após o envio (PATCH envelopes.recipients.update) ignora o PIN: '
            .'o bloqueio fica para sempre.'
    )->and(SenderPins::check($record, $ctx['recipient']->ulid, '73915284'))->toBeTrue();
});
