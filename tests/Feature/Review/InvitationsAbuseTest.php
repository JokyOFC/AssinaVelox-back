<?php

use App\Enums\MembershipRole;
use App\Notifications\MembershipInvitationNotification;
use App\Services\Organizations\Invitations;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de segurança — convites de membros
|--------------------------------------------------------------------------
*/

test('reenvio de convite não tem throttle: um admin pode bombardear a caixa de entrada do convidado', function () {
    Notification::fake();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $invitation = app(Invitations::class)->invite($organization, $owner, 'vitima@example.com', MembershipRole::Member);

    actingAsMember($owner, $organization);

    $statuses = collect(range(1, 25))
        ->map(fn () => $this->post(route('invitations.resend', $invitation))->getStatusCode())
        ->unique()
        ->all();

    // 25 reenvios consecutivos aceitos (302 com flash de sucesso), nenhum 429.
    expect($statuses)->toBe([302]);

    // 1 e-mail da emissão + 25 dos reenvios, todos para o mesmo endereço.
    Notification::assertSentOnDemandTimes(MembershipInvitationNotification::class, 26);
});

/*
| ATUALIZADO na revisão final.
|
| Este caso documentava o defeito: `MembershipInvitationNotification` é `ShouldQueue` e
| carrega `public readonly string $token`, então o token bruto do convite — o segredo aceito
| em /convites/{token} — ia em CLARO para o armazenamento da fila (`jobs` no banco, Redis em
| produção), para `failed_jobs` numa falha definitiva de entrega e para a tela do Horizon.
|
| A correção foi marcar as três notificações que carregam segredo
| (`MembershipInvitationNotification`, `RecipientInvitationNotification` e
| `SignerOtpNotification`) como `ShouldBeEncrypted` e agendar `queue:prune-failed`. O caso
| passa a afirmar o contrário: o payload não pode conter o token.
*/
test('o token bruto do convite não fica em claro na tabela jobs quando a fila é database', function () {
    config()->set('queue.default', 'database');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $invitation = app(Invitations::class)->invite($organization, $owner, 'novo@example.com', MembershipRole::Member);

    $payload = (string) DB::table('jobs')->value('payload');
    expect($payload)->not->toBe('');

    // Nada de `s:5:"token";s:NN:"…"` no payload: ele vai cifrado com a APP_KEY.
    expect(preg_match('/s:5:"token";s:\d+:"([A-Za-z0-9_-]+)"/', $payload, $matches))->toBe(0);

    // E a notificação declara a cifragem, que é o que o Laravel lê para cifrar o job.
    expect(new MembershipInvitationNotification($invitation, 'token-de-teste'))
        ->toBeInstanceOf(ShouldBeEncrypted::class);
});
