<?php

use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Signing\ConsentText;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de design — a declaração de aceite exibe um e-mail que não existe
|--------------------------------------------------------------------------
| `ConsentText::statement()` monta a declaração jurídica com o e-mail mascarado
| do signatário ("Eu, Fulano, identificado(a) nesta solicitação pelo e-mail
| m**********@exemplo.test, declaro que: …") e é esse texto que vai para
| `signature_acceptances.consent_statement`.
|
| Na tela pública o texto passa por `components/sign/legal-text.tsx`, cujo
| componente `Emphasis` divide o texto pela sequência de dois asteriscos para
| transformar trechos em negrito. A máscara de `Recipient::maskEmail()` é
| `inicial + N asteriscos`;
| com um local part de tamanho par os asteriscos formam pares `**` e são
| consumidos como marcação. O signatário lê "m@exemplo.test" — um endereço
| diferente do dele e diferente do que fica gravado como evidência.
|
| Este teste reproduz a regra de renderização do front (a mesma documentada no
| componente) sobre o texto que o servidor entrega.
*/

/** Mesma regra de `Emphasis` em legal-text.tsx: `**` delimita negrito e some do texto. */
function reviewRenderEmphasis(string $text): string
{
    return implode('', explode('**', $text));
}

function reviewConsentFixture(): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create([
        'title' => 'Contrato de locação',
    ]);

    $recipient = Recipient::factory()->forEnvelope($envelope)->create([
        'name' => 'Maria A. Souza',
        'email' => 'maria.souza@exemplo.test',
    ]);

    return [$envelope, $recipient, $organization];
}

it('mantém o e-mail mascarado legível depois da renderização da declaração de aceite', function () {
    [$envelope, $recipient, $organization] = reviewConsentFixture();

    $statement = ConsentText::statement(
        $envelope,
        $recipient,
        $organization,
        str_repeat('a', 64),
        withCertificate: false,
    );

    $masked = $recipient->masked_email;

    expect($statement)->toContain($masked);

    // O que o signatário realmente lê na página pública.
    expect(reviewRenderEmphasis($statement))->toContain($masked);
});

it('não deixa a máscara de e-mail formar marcação de negrito no texto jurídico', function () {
    [, $recipient] = reviewConsentFixture();

    expect($recipient->masked_email)->not->toContain('**');
});
