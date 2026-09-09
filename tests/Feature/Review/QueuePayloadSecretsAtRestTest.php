<?php

use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Signing\SignerOtpNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final de segurança — segredos em claro no armazenamento da fila
|--------------------------------------------------------------------------
| `App\Notifications\Signing\SignerOtpNotification` guarda o código de seis dígitos em
| `public readonly string $code` e implementa `ShouldQueue`. O job serializado
| (`Illuminate\Notifications\SendQueuedNotifications`) carrega a notificação inteira, e
| como nem o job nem a notificação implementam `ShouldBeEncrypted`, o payload vai em
| claro para o armazenamento da fila — `jobs` no banco (dev) ou Redis (produção).
|
| O mesmo vale para `App\Notifications\Envelopes\RecipientInvitationNotification`
| (`$signingUrl` com o token bruto do convite) e para
| `App\Notifications\MembershipInvitationNotification`.
|
| O docblock de SignerOtpNotification (linhas 24-34) assume o compromisso "o código
| viaja no payload do job enquanto ele espera na fila (segundos, normalmente)". Duas
| coisas quebram esse "segundos":
|
| 1. Uma falha definitiva de entrega copia o payload INTEIRO para `failed_jobs`, que não
|    tem prazo: routes/console.php não agenda `queue:prune-failed` (nem
|    `queue:prune-batches`). O código do segundo fator e o token do convite ficam ali
|    para sempre, sobrevivendo ao TTL de 10 min do desafio e à revogação do link.
| 2. O painel do Horizon (App\Providers\HorizonServiceProvider, gate
|    `is_platform_admin`) mostra o payload dos jobs pendentes e falhos na íntegra.
|
| Nada disso é alcançado pelo redator de log (App\Logging\*), que só trata o canal de
| log — ver tests/Feature/Hardening/LogRedactionTest.php.
|
| Os três testes abaixo falham hoje.
*/

beforeEach(fn () => $this->withoutVite());

function otpNotificationFixture(): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();
    $recipient = Recipient::factory()->forEnvelope($envelope)->create();

    return [$envelope, $recipient];
}

it('não deixa o código do segundo fator em claro no armazenamento da fila', function () {
    config(['queue.default' => 'database']);

    [$envelope, $recipient] = otpNotificationFixture();

    $code = '135792';

    Notification::route('mail', $recipient->email)->notify(
        new SignerOtpNotification($recipient, $envelope, $code, 10, 'corr-review'),
    );

    $payload = (string) DB::table('jobs')->value('payload');

    expect($payload)->not->toBe('')
        ->and($payload)->not->toContain($code);
});

it('marca as notificações que carregam segredo como jobs cifrados', function () {
    [$envelope, $recipient] = otpNotificationFixture();

    $notification = new SignerOtpNotification($recipient, $envelope, '135792', 10, 'corr-review');

    expect($notification)->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('poda os jobs falhos, que guardam o payload em claro sem prazo', function () {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'queue:prune-failed'))
        ->values()
        ->all();

    expect($scheduled)->not->toBeEmpty();
});
