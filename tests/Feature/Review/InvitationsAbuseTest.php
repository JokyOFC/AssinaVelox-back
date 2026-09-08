<?php

use App\Enums\MembershipRole;
use App\Notifications\MembershipInvitationNotification;
use App\Services\Organizations\Invitations;
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

test('o token bruto do convite é gravado em claro na tabela jobs quando a fila é database', function () {
    config()->set('queue.default', 'database');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $invitation = app(Invitations::class)->invite($organization, $owner, 'novo@example.com', MembershipRole::Member);

    $payload = DB::table('jobs')->value('payload');
    expect($payload)->not->toBeNull();

    $command = json_decode((string) $payload, true)['data']['command'] ?? '';

    // A notificação (ShouldQueue) carrega `public readonly string $token` — serializado no job.
    expect(preg_match('/s:5:"token";s:\d+:"([A-Za-z0-9_-]+)"/', $command, $matches))->toBe(1);

    $rawToken = $matches[1];

    // Prova de que é exatamente o segredo aceito em /convites/{token}: o digest bate com o banco.
    expect(hash('sha256', $rawToken))->toBe($invitation->token_digest);
    expect(Invitations::findByToken($rawToken)?->is($invitation))->toBeTrue();
});
