<?php

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Http\Resources\RecipientResource;
use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Http\Request;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — rótulo do badge de signatário
|--------------------------------------------------------------------------
| ROUTES_AND_PAGES §6.2 e DESIGN_SYSTEM §5.2/§8.1: o BADGE de um signatário ainda
| não assinado (pending/notified/viewed) é "Pendente" (âmbar); o detalhe
| ("Enviado · não visualizou", "Visualizou em …", "Aguarda a vez") vai na `note`
| abaixo do badge, não no badge. O front (`recipientStatusLabels` em
| resources/js/lib/labels.ts) já segue a regra, mas prefere `status_label` do
| backend, que hoje devolve "Enviado"/"Visualizou" (RecipientStatus::label()).
*/

function reviewRecipientInSentEnvelope(RecipientStatus $status): Recipient
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create([
        'status' => EnvelopeStatus::InProgress,
        'sent_at' => now()->subDay(),
    ]);

    $recipient = Recipient::factory()->forEnvelope($envelope, 1)->create([
        'status' => $status,
        'last_notified_at' => $status === RecipientStatus::Pending ? null : now()->subHours(2),
    ]);

    $recipient->setRelation('envelope', $envelope->fresh(['organization']));
    $recipient->setRelation('acceptance', null);

    return $recipient;
}

it('rotula signatário notificado como "Pendente" no badge (ROUTES §6.2), com o detalhe na nota', function () {
    $recipient = reviewRecipientInSentEnvelope(RecipientStatus::Notified);

    $payload = RecipientResource::make($recipient)->resolve(Request::create('/'));

    expect($payload['status'])->toBe('notified')
        ->and($payload['status_label'])->toBe('Pendente');
});

it('rotula signatário que visualizou como "Pendente" no badge (DESIGN §5.2)', function () {
    $recipient = reviewRecipientInSentEnvelope(RecipientStatus::Viewed);

    $payload = RecipientResource::make($recipient)->resolve(Request::create('/'));

    expect($payload['status'])->toBe('viewed')
        ->and($payload['status_label'])->toBe('Pendente');
});

it('mantém os rótulos terminais do signatário conforme a spec', function () {
    expect(RecipientStatus::Signed->label())->toBe('Assinado')
        ->and(RecipientStatus::Refused->label())->toBe('Recusado')
        ->and(RecipientStatus::Expired->label())->toBe('Expirado')
        ->and(RecipientStatus::Canceled->label())->toBe('Cancelado');
});

it('espelha em PHP os mesmos rótulos de badge que resources/js/lib/labels.ts (recipientStatusLabels)', function () {
    $labelsTs = (string) file_get_contents(base_path('resources/js/lib/labels.ts'));

    preg_match('/recipientStatusLabels[^{]*\{(.*?)\}/s', $labelsTs, $match);
    preg_match_all("/(\w+):\s*'([^']*)'/", $match[1], $pairs, PREG_SET_ORDER);

    $front = collect($pairs)->mapWithKeys(fn (array $p) => [$p[1] => $p[2]]);

    foreach (RecipientStatus::cases() as $status) {
        expect($status->label())
            ->toBe($front[$status->value], "RecipientStatus::{$status->name}: PHP '{$status->label()}' × TS '{$front[$status->value]}'");
    }
});
