<?php

use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\RecipientAccessLink;
use App\Notifications\Envelopes\EnvelopeExpiringNotification;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\Envelopes\Sending\ExpireEnvelopes;
use App\Services\Envelopes\Sending\ResendInvitations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda A — visualizador tratado como pendente de assinatura
|--------------------------------------------------------------------------
| docs/fase-2/multi-documento-e-papeis.md §3: o visualizador fica em `notified/viewed` e
| "NUNCA é pendência" (`Recipient::isPendingParticipant()`). Os lembretes automáticos o
| excluem, mas dois caminhos de cobrança que já existiam na Fase 1 continuam selecionando por
| STATUS (`pending|notified|viewed`), sem olhar o papel:
|
|  - `ExpireEnvelopes::warn()` — o aviso "o prazo para assinar termina" vai ao visualizador e,
|    como emite um link novo, REVOGA o link somente leitura que ele recebeu no envio;
|  - `ResendInvitations::all()` ("Lembrar pendentes") — no paralelo, reenvia o convite ao
|    visualizador (link novo, `notification_count` +1), como se ele devesse agir.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();

    Event::fake([EnvelopeReadyForFinalization::class]);
    Notification::fake();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/**
 * Quantas notificações da classe `$class` foram enviadas sob demanda para o e-mail `$email`.
 */
function rv2domSentOnDemandTo(string $class, string $email): int
{
    $count = 0;

    foreach (Notification::sentNotifications() as $byId) {
        foreach ($byId as $byClass) {
            foreach ($byClass[$class] ?? [] as $entry) {
                if (($entry['notifiable']->routes['mail'] ?? null) === $email) {
                    $count++;
                }
            }
        }
    }

    return $count;
}

function rv2domViewerEnvelope(): array
{
    return domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
    ]);
}

test('aviso de prazo acabando não vai ao visualizador nem revoga o link dele', function () {
    $ctx = rv2domViewerEnvelope();
    $viewer = $ctx['recipients']['victor@exemplo.test'];
    $viewerLink = RecipientAccessLink::query()->where('recipient_id', $viewer->id)->sole();

    expect(app(ExpireEnvelopes::class)->warn($ctx['envelope']->fresh()))->toBeTrue();

    // O aviso saiu para quem precisa assinar…
    Notification::assertSentOnDemand(
        EnvelopeExpiringNotification::class,
        fn ($notification, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'maria@exemplo.test',
    );

    // …mas não pode ir para quem só acompanha, nem derrubar o link dele.
    expect(rv2domSentOnDemandTo(EnvelopeExpiringNotification::class, 'victor@exemplo.test'))->toBe(0)
        ->and($viewerLink->fresh()->revoked_at)->toBeNull();
});

test('"Lembrar pendentes" no paralelo não reenvia convite ao visualizador', function () {
    $ctx = rv2domViewerEnvelope();
    $viewer = $ctx['recipients']['victor@exemplo.test'];

    $result = app(ResendInvitations::class)->all($ctx['envelope']->fresh());

    Notification::assertSentOnDemand(
        RecipientInvitationNotification::class,
        fn ($notification, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'maria@exemplo.test',
    );

    expect(rv2domSentOnDemandTo(RecipientInvitationNotification::class, 'victor@exemplo.test'))->toBe(0)
        ->and($result['sent'])->toBe(1)
        ->and((int) $viewer->fresh()->notification_count)->toBe(1);
});
