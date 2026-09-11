<?php

use App\Enums\AuthMethod;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../Phase2/Channels/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial (onda B, produto) — celular errado / canal indisponível sem conserto
|--------------------------------------------------------------------------
| Participante com código por SMS/WhatsApp depende de um celular digitado pelo remetente.
| Se o número estiver errado, o código nunca chega; se o provedor ficar indisponível, a
| página pública diz (otp-card.tsx:237-240):
|   "Fale com <remetente> (<organização>) para receber o documento por outro canal."
|
| Depois do envio, o remetente não consegue corrigir nem uma coisa nem outra: o diálogo
| "Editar signatário" do detalhe (envelopes/show.tsx:1366-1369) tem só nome e e-mail, e o
| PATCH envelopes.recipients.update (EnvelopeRecipientController.php:54-57) valida e grava
| só esses dois. Trocar o e-mail não resolve: o código continua indo para o celular.
| A única saída é cancelar o documento e refazer do zero.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-2b-channel-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('o remetente corrige o celular de um participante com código por SMS depois do envio', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp, '+5511912345678');
    channelsEnable($ctx['organization']);
    actingAsMember($ctx['owner'], $ctx['organization']);

    $this->patch(route('envelopes.recipients.update', [$ctx['envelope'], $ctx['recipient']]), [
        'name' => $ctx['recipient']->name,
        'email' => $ctx['recipient']->email,
        'phone' => '+5511987654321',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($ctx['recipient']->fresh()->phone)->toBe(
        '+5511987654321',
        'Com o celular digitado errado, o código por SMS nunca chega ao participante e o '
            .'único caminho de edição após o envio ignora o telefone.'
    );
});

it('o remetente troca o canal do código quando o SMS fica indisponível, como a página pública orienta', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp, '+5511912345678');
    channelsEnable($ctx['organization']);
    actingAsMember($ctx['owner'], $ctx['organization']);

    $this->patch(route('envelopes.recipients.update', [$ctx['envelope'], $ctx['recipient']]), [
        'name' => $ctx['recipient']->name,
        'email' => $ctx['recipient']->email,
        'auth_method' => AuthMethod::EmailOtp->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($ctx['recipient']->fresh()->auth_method)->toBe(
        AuthMethod::EmailOtp,
        'A página pública manda o participante pedir ao remetente "outro canal", mas o '
            .'remetente não tem como mudar o método do código depois do envio.'
    );
});
