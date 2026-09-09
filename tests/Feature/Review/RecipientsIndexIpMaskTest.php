<?php

use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final (produto) — a tela "Assinaturas" ignora evidence_show_ip
|--------------------------------------------------------------------------
| `organizations.settings.evidence_show_ip` nasce `masked` (arquitetura §3.1) e
| App\Support\IpDisplay foi criado justamente para centralizar essa decisão. O docblock
| da própria classe diz que ela governa "**todas** as telas do remetente — o detalhe do
| documento, a trilha de auditoria e a página de evidências". São três. A quarta tela que
| mostra o mesmo dado pessoal — `/assinaturas` (ROUTES §2.9) — não passa por ela:
|
|   app/Http/Resources/RecipientListItemResource.php:84
|     RecipientStatus::Signed => 'IP '.($acceptance->ip_address ?? '—').' · '....
|
| Observado no navegador, na mesma instalação e na mesma organização, para o MESMO aceite
| (AV-00010, Bruno Carvalho Lima):
|
|   /documentos/{ulid}      → "Aceite registrado em 02/09/2026 12:01 · IP 70.72.***.***"
|   /documentos/{ulid}/evidencias → "70.72.***.***"
|   /assinaturas            → "IP 70.72.227.81 · Mac (Edge)"
|
| A tela de lista — a que um operador deixa aberta o dia inteiro, exporta e projeta em
| reunião — é a única que entrega o endereço inteiro. `evidence_show_ip = none` também
| não é respeitado: não há caminho para esconder o IP nessa tela.
*/

function reviewSignaturesListEnvelope(string $ip): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->completed()->create();
    $recipient = Recipient::factory()->forEnvelope($envelope)->signed()->create();

    SignatureAcceptance::factory()->forRecipient($recipient)->create([
        'ip_address' => $ip,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36',
    ]);

    return [$organization, $owner];
}

it('mascara o IP do signatário na tela Assinaturas quando evidence_show_ip = masked (padrão)', function () {
    [$organization, $owner] = reviewSignaturesListEnvelope('203.0.113.77');

    actingAsMember($owner, $organization);

    $rows = $this->get(route('recipients.index'))
        ->assertOk()
        ->viewData('page')['props']['recipients']['data'];

    expect($rows[0]['note'])
        ->not->toContain('203.0.113.77')
        ->and($rows[0]['note'])->toContain('203.0.***.***');
});

it('esconde o IP na tela Assinaturas quando a organização escolhe evidence_show_ip = none', function () {
    [$organization, $owner] = reviewSignaturesListEnvelope('198.51.100.9');

    $organization->forceFill([
        'settings' => array_merge((array) $organization->settings, ['evidence_show_ip' => 'none']),
    ])->save();

    actingAsMember($owner, $organization);

    $rows = $this->get(route('recipients.index'))
        ->assertOk()
        ->viewData('page')['props']['recipients']['data'];

    expect($rows[0]['note'])->not->toContain('198.51.100');
});
