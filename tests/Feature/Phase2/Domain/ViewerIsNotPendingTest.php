<?php

use App\Enums\RecipientRole;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Notifications\Envelopes\RecipientSignedNotification;
use App\Services\Envelopes\Sending\EnvelopeNotifications;
use App\Services\Organizations\DailyDigest;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.4 — o visualizador acompanha, não é pendência
|--------------------------------------------------------------------------
| `RecipientRole::participates()` já separa quem tem aceite a dar (signatário,
| testemunha, aprovador) de quem só recebe cópia (visualizador), e o painel e o
| "Lembrar pendentes" já usavam esse corte. Quatro pontos ainda contavam o visualizador
| como alguém que falta assinar:
|
| - a aba "Pendentes" e os indicadores da tela Assinaturas;
| - o aviso "Fulano assinou" ao remetente ("1 de 2" com um signatário e um visualizador,
|   para sempre incompleto);
| - o resumo diário ("faltam N assinaturas" num envelope que só espera o visualizador).
|
| Em cada caso a regra é a mesma: pendência é de quem participa da coleta.
*/

beforeEach(fn () => $this->withoutVite());

/**
 * Envelope em andamento com um signatário ainda pendente, um signatário que já
 * assinou e um visualizador que abriu o link.
 *
 * @return array{envelope: Envelope, signer: Recipient, signed: Recipient, viewer: Recipient, owner: mixed, organization: mixed}
 */
function viewerScenario(): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create();

    $signer = Recipient::factory()->forEnvelope($envelope)->notified()->create([
        'role' => RecipientRole::Signer,
    ]);
    $signed = Recipient::factory()->forEnvelope($envelope)->signed()->create([
        'role' => RecipientRole::Signer,
    ]);
    $viewer = Recipient::factory()->forEnvelope($envelope)->viewed()->create([
        'role' => RecipientRole::Viewer,
    ]);

    return compact('envelope', 'signer', 'signed', 'viewer', 'owner', 'organization');
}

/**
 * A listagem paginada da tela Assinaturas, qualquer que seja o nome da prop:
 * é a única com o formato `Paginated<T>` (data + meta).
 *
 * @param  array<string, mixed>  $props
 * @return array<string, mixed>
 */
function paginatedProp(array $props): array
{
    foreach ($props as $value) {
        if (is_array($value) && array_key_exists('data', $value) && array_key_exists('meta', $value)) {
            return $value;
        }
    }

    throw new RuntimeException('Nenhuma prop paginada na resposta da tela Assinaturas.');
}

it('não lista o visualizador na aba Pendentes da tela Assinaturas', function () {
    ['signer' => $signer, 'viewer' => $viewer, 'owner' => $owner, 'organization' => $organization] = viewerScenario();

    actingAsMember($owner, $organization);

    $props = $this->get(route('recipients.index', ['status' => 'pending']))
        ->assertOk()
        ->viewData('page')['props'];

    $ids = collect(paginatedProp($props)['data'])->pluck('id')->all();

    expect($ids)->toContain($signer->ulid)
        ->and($ids)->not->toContain($viewer->ulid);
});

it('não conta o visualizador nos indicadores de pendentes, visualizados e no total da tela Assinaturas', function () {
    ['owner' => $owner, 'organization' => $organization] = viewerScenario();

    actingAsMember($owner, $organization);

    $kpis = $this->get(route('recipients.index'))
        ->assertOk()
        ->viewData('page')['props']['kpis'];

    // Só o signatário notificado está pendente; o visualizador "visualizou", mas isso
    // não é passo de coleta para ele.
    expect($kpis['pending']['value'])->toBe(1)
        ->and($kpis['pending']['viewed'])->toBe(0);
});

it('conta no aviso "Fulano assinou" só quem tem aceite a dar', function () {
    Notification::fake();

    ['envelope' => $envelope, 'signed' => $signed, 'owner' => $owner] = viewerScenario();

    app(EnvelopeNotifications::class)->notifySenderSigned($envelope->fresh(), $signed);

    Notification::assertSentTo(
        $owner,
        RecipientSignedNotification::class,
        fn (RecipientSignedNotification $notification): bool => $notification->signedCount === 1
            && $notification->totalCount === 2,
    );
});

it('não põe no resumo diário um envelope que só espera o visualizador', function () {
    ['envelope' => $envelope, 'signer' => $signer] = viewerScenario();

    $pendingCount = new ReflectionMethod(DailyDigest::class, 'pendingCount');

    // Com o signatário ainda pendente, o resumo diz "falta 1" — não "faltam 2".
    expect($pendingCount->invoke(app(DailyDigest::class), $envelope->fresh()))->toBe(1);

    // Assinado o último signatário, sobra só o visualizador: nada falta.
    $signer->forceFill(['status' => 'signed', 'signed_at' => now()])->save();

    expect($pendingCount->invoke(app(DailyDigest::class), $envelope->fresh()))->toBe(0);
});
