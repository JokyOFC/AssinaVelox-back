<?php

use App\Enums\RecipientRole;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Envelopes\EnvelopeCanceledNotification;
use App\Notifications\Envelopes\EnvelopeExpiringNotification;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Notifications\Envelopes\RecipientReminderNotification;
use App\Notifications\Signing\SignerOtpNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;

require_once __DIR__.'/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| F-I18N — e-mails ao participante no idioma dele (docs/fase-3/multilingue.md §6)
|--------------------------------------------------------------------------
| O idioma vai em `Notification::$locale` (o Laravel troca o idioma da aplicação só durante o
| envio daquela mensagem). Com a flag desligada, `$locale` fica nulo e o texto é o de sempre.
*/

beforeEach(fn () => $this->withoutVite());

/**
 * @return array{envelope: Envelope, recipient: Recipient}
 */
function i18nMailScenario(string $locale, bool $flag = true, array $envelope = [], RecipientRole $role = RecipientRole::Signer): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora']);
    i18nEnableFlag($organization, $flag);

    $model = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(array_merge([
        'title' => 'Contrato de locação',
        'expires_at' => now()->addDays(3)->setTime(17, 30),
    ], $envelope));

    $recipient = Recipient::factory()->forEnvelope($model)->create([
        'name' => 'Maria Alves Souza',
        'locale' => $locale,
        'role' => $role,
    ]);

    return ['envelope' => $model->fresh(), 'recipient' => $recipient->fresh()];
}

/**
 * Renderiza como o envio real faz: no idioma da notificação.
 */
function i18nRender(object $notification, MailMessage $message): string
{
    $previous = App::getLocale();
    App::setLocale($notification->locale ?? $previous);

    try {
        return (string) $message->render();
    } finally {
        App::setLocale($previous);
    }
}

it('mantém o convite em PT-BR, idêntico, com a flag desligada — mesmo com idioma gravado', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = i18nMailScenario('en', flag: false);
    $url = 'https://app.test/assinar/'.str_repeat('a', 43);

    $notification = new RecipientInvitationNotification($recipient, $envelope, $url, 'corr-1');
    $mail = $notification->toMail($recipient);

    expect($notification->locale)->toBeNull()
        ->and($mail->subject)->toBe('Imobiliária Aurora enviou um documento para você assinar')
        ->and($mail->greeting)->toBe('Olá, Maria!')
        ->and($mail->actionText)->toBe('Revisar e assinar')
        ->and($mail->introLines[0])->toContain('para você assinar eletronicamente.')
        ->and(implode(' ', $mail->outroLines))->toContain('O prazo para assinar termina em');
});

it('envia o convite em inglês, com o nome da organização e o link intactos', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = i18nMailScenario('en');
    $url = 'https://app.test/assinar/'.str_repeat('b', 43);

    $notification = new RecipientInvitationNotification($recipient, $envelope, $url, 'corr-2');
    $mail = $notification->toMail($recipient);
    $html = i18nRender($notification, $mail);

    expect($notification->locale)->toBe('en')
        ->and($mail->subject)->toBe('Imobiliária Aurora sent you a document to sign')
        ->and($mail->greeting)->toBe('Hello, Maria!')
        ->and($mail->actionText)->toBe('Review and sign')
        ->and($mail->actionUrl)->toBe($url)
        ->and($mail->introLines[0])->toContain('**Imobiliária Aurora**')
        ->and($mail->introLines[0])->toContain('to sign electronically')
        ->and(implode(' ', $mail->outroLines))->toContain('The deadline to sign is')
        ->and($html)->toContain($url)
        ->and($html)->not->toContain('Olá')
        ->and($html)->not->toContain('Revisar e assinar');
});

it('envia o convite em espanhol, com o prazo no formato do idioma e no fuso do participante', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = i18nMailScenario('es');
    $recipient->forceFill(['timezone' => 'Europe/Madrid'])->save();

    $notification = new RecipientInvitationNotification($recipient->fresh(), $envelope, 'https://app.test/assinar/x', 'corr-3');
    $mail = $notification->toMail($recipient);
    $deadline = $envelope->expires_at->copy()->setTimezone('Europe/Madrid')->locale('es')->isoFormat('D [de] MMMM [de] YYYY [a las] HH:mm');

    expect($notification->locale)->toBe('es')
        ->and($mail->subject)->toBe('Imobiliária Aurora le envió un documento para firmar')
        ->and($mail->greeting)->toBe('¡Hola, Maria!')
        ->and(implode(' ', $mail->outroLines))->toContain('El plazo para firmar termina el '.$deadline.'.');
});

it('escapa título e mensagem do remetente também no e-mail traduzido', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = i18nMailScenario('en', envelope: [
        'title' => '[Contrato](https://evil.example/phish)',
        'message' => '[Confirme seus dados](https://evil.example/phish)',
    ]);

    $notification = new RecipientInvitationNotification($recipient, $envelope, 'https://app.test/assinar/x', 'corr-4');
    $html = i18nRender($notification, $notification->toMail($recipient));

    expect($html)->toContain('Message from the sender')
        ->and($html)->not->toContain('href="https://evil.example/phish"');
});

it('traduz o convite dos demais papéis', function (RecipientRole $role, string $subject, string $action) {
    ['envelope' => $envelope, 'recipient' => $recipient] = i18nMailScenario('en', role: $role);

    $mail = (new RecipientInvitationNotification($recipient, $envelope, 'https://app.test/assinar/x', 'corr-5'))->toMail($recipient);

    expect($mail->subject)->toBe($subject)
        ->and($mail->actionText)->toBe($action);
})->with([
    [RecipientRole::Witness, 'Imobiliária Aurora asked you to sign a document as a witness', 'Review and sign as a witness'],
    [RecipientRole::Approver, 'Imobiliária Aurora sent you a document for your approval', 'Review and approve'],
    [RecipientRole::Viewer, 'Imobiliária Aurora shared a document with you', 'Follow the document'],
]);

it('traduz lembrete, código, cancelamento e prazo acabando', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = i18nMailScenario('en');

    $reminder = (new RecipientReminderNotification($recipient, $envelope, 'https://app.test/r', 'c', 1, 3))->toMail($recipient);
    $otp = (new SignerOtpNotification($recipient, $envelope, '123456', 10, 'c'))->toMail($recipient);
    $canceled = (new EnvelopeCanceledNotification($recipient, $envelope, 'c', 'Valor errado'))->toMail($recipient);
    $expiring = (new EnvelopeExpiringNotification($recipient, $envelope, 'https://app.test/e', 'c'))->toMail($recipient);

    expect($reminder->subject)->toBe('Reminder: Contrato de locação is waiting for your signature')
        ->and($reminder->actionText)->toBe('Open and sign')
        ->and($otp->subject)->toBe('Your code to sign Contrato de locação')
        ->and($otp->introLines)->toContain('**123 456**')
        ->and($otp->introLines)->toContain('The code is valid for 10 minutes and can only be used once.')
        ->and($canceled->subject)->toBe('Document closed: Contrato de locação')
        ->and($canceled->introLines)->toContain('Reason given: "Valor errado"')
        ->and($expiring->subject)->toBe('Your deadline to sign Contrato de locação is ending')
        ->and($expiring->actionText)->toBe('Sign now');
});

it('mantém PT-BR para o participante em pt_BR com a flag ligada', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = i18nMailScenario('pt_BR');

    $notification = new SignerOtpNotification($recipient, $envelope, '654321', 10, 'c');
    $mail = $notification->toMail($recipient);

    expect($notification->locale)->toBeNull()
        ->and($mail->subject)->toBe('Seu código para assinar Contrato de locação')
        ->and($mail->introLines)->toContain('O código vale por 10 minutos e só pode ser usado uma vez.');
});

it('ignora um idioma gravado fora da lista fechada', function () {
    ['envelope' => $envelope, 'recipient' => $recipient] = i18nMailScenario('en');
    Recipient::withoutOrganizationScope()->whereKey($recipient->getKey())->update(['locale' => '../../etc']);

    $notification = new SignerOtpNotification($recipient->fresh(), $envelope, '111222', 10, 'c');

    expect($notification->locale)->toBeNull()
        ->and($notification->toMail($recipient)->subject)->toBe('Seu código para assinar Contrato de locação');
});
