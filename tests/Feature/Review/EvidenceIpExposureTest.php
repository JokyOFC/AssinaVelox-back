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
| organizations.settings.evidence_show_ip = masked é o padrão (arquitetura §3),
| mas a página de evidências e o detalhe do documento entregam o IP completo.
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

test('envelopes.evidence expõe o IP completo e o user agent bruto do signatário mesmo com evidence_show_ip = masked', function () {
    [$organization, $owner, $envelope] = reviewSignedEnvelopeWithAcceptance('203.0.113.77');

    actingAsMember($owner, $organization);

    $response = $this->get(route('envelopes.evidence', $envelope));
    $response->assertOk();

    $recipients = $response->viewData('page')['props']['recipients'];

    expect($recipients[0]['ip'])->toBe('203.0.113.77');
    expect($recipients[0]['user_agent'])->toContain('Mozilla/5.0');
});

test('envelopes.show expõe o IP completo do signatário em recipients[].evidence.ip', function () {
    [$organization, $owner, $envelope] = reviewSignedEnvelopeWithAcceptance('203.0.113.77');

    actingAsMember($owner, $organization);

    $response = $this->get(route('envelopes.show', $envelope));
    $response->assertOk();

    $recipients = $response->viewData('page')['props']['recipients'];

    expect($recipients[0]['evidence']['ip'])->toBe('203.0.113.77');
});
