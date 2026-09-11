<?php

use App\Enums\AuthMethod;
use App\Rules\PhoneE164;
use Illuminate\Support\Facades\Validator;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Telefone em E.164 (libphonenumber, região padrão BR) — recipients.phone
|--------------------------------------------------------------------------
*/

beforeEach(fn () => $this->withoutVite());

it('normaliza celulares válidos em E.164', function (string $raw, string $expected) {
    expect(PhoneE164::normalize($raw))->toBe($expected);
})->with([
    'nacional com máscara' => ['(11) 91234-5678', '+5511912345678'],
    'com DDI e espaços' => ['+55 11 91234-5678', '+5511912345678'],
    'só dígitos' => ['11912345678', '+5511912345678'],
    'outro país' => ['+351 912 345 678', '+351912345678'],
]);

it('recusa telefone inválido ou que não recebe SMS', function (string $raw) {
    expect(PhoneE164::normalize($raw))->toBeNull();
})->with([
    'curto' => ['123'],
    'letras' => ['abc'],
    'fixo no Brasil' => ['(11) 3123-4567'],
    'vazio' => [''],
    'longo demais' => [str_repeat('9', 40)],
]);

it('aceita fixo quando o uso não exige celular', function () {
    expect(PhoneE164::normalize('(11) 3123-4567', mobileOnly: false))->toBe('+551131234567');
});

it('a regra de validação usa a mesma normalização e responde em português', function () {
    expect(Validator::make(['p' => '(11) 91234-5678'], ['p' => [new PhoneE164]])->passes())->toBeTrue();

    $validator = Validator::make(['p' => '123'], ['p' => [new PhoneE164]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('p'))->toBe('Informe um celular válido com DDD, por exemplo +55 11 91234-5678.');
});

it('mascara o número sem expor o meio', function () {
    expect(PhoneE164::mask('+5511912345678'))->toBe('+55 •••••••5678')
        ->and(PhoneE164::mask(null))->toBe('');
});

it('o sync normaliza o telefone e exige celular válido para SMS e WhatsApp', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    channelsEnable($organization);
    $envelope = draftWithDocument($organization, $owner);
    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => null, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'auth_method' => 'sms_otp', 'phone' => '(11) 91234-5678'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $recipient = $envelope->fresh()->recipients()->sole();

    expect($recipient->phone)->toBe('+5511912345678')
        ->and($recipient->auth_method)->toBe(AuthMethod::SmsOtp);

    // WhatsApp sem telefone: recusado.
    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => $recipient->ulid, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'auth_method' => 'whatsapp_otp', 'phone' => ''],
        ],
    ])->assertSessionHasErrors(['recipients.0.phone' => 'Informe o celular (com DDD) para enviar por SMS ou WhatsApp.']);

    // Telefone inválido: recusado, nada muda.
    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => $recipient->ulid, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'auth_method' => 'sms_otp', 'phone' => '1234'],
        ],
    ])->assertSessionHasErrors(['recipients.0.phone' => 'Informe um celular válido com DDD, por exemplo +55 11 91234-5678.']);

    expect($recipient->fresh()->phone)->toBe('+5511912345678')
        ->and($recipient->fresh()->auth_method)->toBe(AuthMethod::SmsOtp);

    // Convite por WhatsApp com código por e-mail: canal gravado, telefone normalizado.
    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['id' => $recipient->ulid, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'auth_method' => 'email_otp', 'channel' => 'whatsapp', 'phone' => '+55 11 98765-4321'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $recipient->refresh();

    expect($recipient->getAttribute('delivery_channel'))->toBe('whatsapp')
        ->and($recipient->phone)->toBe('+5511987654321')
        ->and($recipient->auth_method)->toBe(AuthMethod::EmailOtp);
});
