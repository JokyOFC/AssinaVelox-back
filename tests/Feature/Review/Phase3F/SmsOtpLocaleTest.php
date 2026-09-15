<?php

use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../Phase2/Channels/Support/ChannelHelpers.php';
require_once __DIR__.'/../../Phase3/I18n/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda F (produto/multilíngue) — código por SMS sai em português
|--------------------------------------------------------------------------
| Com `multilingual` ligada, o remetente escolhe "Idioma dos e-mails e da página: English" para
| o participante. A página pública sai em inglês ("We sent a code by SMS to +55 •••••••5678") e
| o e-mail do código também (SignerOtpNotification::$locale) — mas o SMS/WhatsApp do código é
| montado por ChannelMessages::otpText, só em PT-BR, sem o idioma do participante.
|
| Visto no QA (banco review-3f-produto, "Aditivo de reajuste — Contrato 2024/118", Ana em
| inglês, autenticação por SMS simulado): a mensagem registrada no SimulatedOutbox foi
|   "AssinaVelox: seu código de confirmação para o documento "…" é 340122. Vale 10 min. Não
|    compartilhe este código."
| A participante que não lê português recebe o código num idioma que o remetente disse que ela
| não usa. docs/fase-3/multilingue.md e o relatório I-3F dizem "e-mails de convite e de código
| em inglês" — o canal SMS/WhatsApp ficou de fora. O mesmo vale para o convite por SMS
| (ChannelMessages::invitationText).
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-3f-sms-locale-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('o código por SMS sai no idioma escolhido para o participante quando o multilíngue está ligado', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp);
    channelsEnable($ctx['organization']);
    i18nEnableFlag($ctx['organization']);

    $ctx['recipient']->forceFill(['locale' => 'en'])->save();

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertRedirect();

    $entry = channelsOutbox()->last(DeliveryChannel::Sms);

    expect($entry)->not->toBeNull('Nenhum SMS simulado foi registrado.');

    $text = (string) ($entry['text'] ?? '');

    expect($text)->not->toContain('seu código de confirmação')
        ->and($text)->not->toContain('Não compartilhe')
        ->and(mb_strtolower($text))->toContain('code');
});

it('sem a flag multilíngue, o SMS do código continua o de sempre (em PT-BR)', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp);
    channelsEnable($ctx['organization']);
    i18nEnableFlag($ctx['organization'], false);

    $ctx['recipient']->forceFill(['locale' => 'en'])->save();

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertRedirect();

    expect((string) (channelsOutbox()->last(DeliveryChannel::Sms)['text'] ?? ''))
        ->toContain('seu código de confirmação');
});
