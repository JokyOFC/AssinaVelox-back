<?php

use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Support\OrganizationSettings;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de segurança — exposição do IP do signatário (LGPD / minimização)
|--------------------------------------------------------------------------
| organizations.settings.evidence_show_ip = masked é o padrão (arquitetura §3.1) e
| governa as TRÊS telas do remetente: detalhe do documento, trilha e evidências.
| Elas divergiam — a trilha mascarava dois octetos lendo config/, o card do
| signatário e a página de evidências entregavam o endereço inteiro. Hoje as três
| passam por App\Support\IpDisplay.
*/

function reviewSignedEnvelopeWithAcceptance(string $ip): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->completed()->create();
    $recipient = Recipient::factory()->forEnvelope($envelope)->signed()->create();
    SignatureAcceptance::factory()->forRecipient($recipient)->create([
        'ip_address' => $ip,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36',
    ]);

    return [$organization, $owner, $envelope];
}

test('a organização nasce com evidence_show_ip = masked', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    expect(OrganizationSettings::of($organization)->get('evidence_show_ip'))->toBe('masked');
});

test('envelopes.evidence mascara o IP do signatário quando evidence_show_ip = masked', function () {
    [$organization, $owner, $envelope] = reviewSignedEnvelopeWithAcceptance('203.0.113.77');

    actingAsMember($owner, $organization);

    $recipients = $this->get(route('envelopes.evidence', $envelope))
        ->assertOk()
        ->viewData('page')['props']['recipients'];

    expect($recipients[0]['ip'])->toBe('203.0.***.***');
});

test('envelopes.show mascara o IP do signatário em recipients[].evidence.ip', function () {
    [$organization, $owner, $envelope] = reviewSignedEnvelopeWithAcceptance('203.0.113.77');

    actingAsMember($owner, $organization);

    $recipients = $this->get(route('envelopes.show', $envelope))
        ->assertOk()
        ->viewData('page')['props']['recipients'];

    expect($recipients[0]['evidence']['ip'])->toBe('203.0.***.***');
});

test('as três telas do remetente concordam entre si e respeitam full e none', function () {
    [$organization, $owner, $envelope] = reviewSignedEnvelopeWithAcceptance('203.0.113.77');

    actingAsMember($owner, $organization);

    OrganizationSettings::of($organization)->put(['evidence_show_ip' => 'full']);

    $props = $this->get(route('envelopes.show', $envelope))->viewData('page')['props'];
    $evidence = $this->get(route('envelopes.evidence', $envelope))->viewData('page')['props'];

    expect($props['recipients'][0]['evidence']['ip'])->toBe('203.0.113.77')
        ->and($evidence['recipients'][0]['ip'])->toBe('203.0.113.77');

    OrganizationSettings::of($organization->fresh())->put(['evidence_show_ip' => 'none']);

    $props = $this->get(route('envelopes.show', $envelope))->viewData('page')['props'];
    $evidence = $this->get(route('envelopes.evidence', $envelope))->viewData('page')['props'];

    // `none`: nenhuma das telas devolve endereço; o card usa o travessão da interface.
    expect($props['recipients'][0]['evidence']['ip'])->toBe('—')
        ->and($evidence['recipients'][0]['ip'])->toBeNull();
});

test('o user agent bruto continua sendo entregue — decisão registrada, não descuido', function () {
    // Diferente do IP, o user agent não é endereço: ele é a identificação do navegador que
    // a própria página de evidências precisa citar por extenso para que a evidência seja
    // conferível. `evidence_show_ip` não fala dele. Se a política mudar, muda aqui.
    [$organization, $owner, $envelope] = reviewSignedEnvelopeWithAcceptance('203.0.113.77');

    actingAsMember($owner, $organization);

    $recipients = $this->get(route('envelopes.evidence', $envelope))->viewData('page')['props']['recipients'];

    expect($recipients[0]['user_agent'])->toContain('Mozilla/5.0');
});
