<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (fidelidade de design / contratos de props) — busca ⌘K
|--------------------------------------------------------------------------
| `SearchController@index` monta o JSON à mão e NÃO entrega as chaves que
| `resources/js/components/command-search.tsx` consome:
|
|  - signatários: a página lê `recipient.envelope_id` (onSelect → openEnvelope)
|    e `recipient.envelope_title` (subtítulo). O controller devolve `envelope:
|    {id, display_code, title}`. Resultado observado no navegador: as linhas do
|    grupo "Signatários" saem como "email · " (título vazio) e o clique quebra
|    com `TypeError: Cannot read properties of undefined (reading 'toString')`.
|  - documentos: a página lê `envelope.signed_count` (cor do badge) e
|    `envelope.folder`. Sem `signed_count`, `EnvelopeStatusBadge` cai no default
|    0 e pinta "Em andamento" de âmbar (#9a5b00/#fff4e0) em vez do azul de
|    DESIGN_SYSTEM §5.1 (#1257c9/#e8f0fd/#c9dbf7).
*/

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
 * Nota de correção (não enfraquece a asserção): `toHaveKey(string $key, mixed $value = null)`
 * do Pest interpreta o 2º argumento como o VALOR esperado, não como mensagem de falha. As
 * mensagens originais viraram comentários e as asserções de valor logo abaixo (ULID não vazio,
 * título exato, signed_count === 1) continuam intactas.
 */

beforeEach(fn () => $this->withoutVite());

function searchFixture(): array
{
    $context = createOrganizationWithOwner(['name' => 'Imobiliária Horizonte']);
    $organization = $context['organization'];
    $owner = $context['owner'];

    actingAsMember($owner, $organization);

    $envelope = Envelope::factory()
        ->forOrganization($organization, $owner)
        ->create([
            'title' => 'Contrato de locação comercial',
            'status' => EnvelopeStatus::InProgress,
            'sent_at' => now()->subDays(2),
        ]);

    Recipient::factory()->forEnvelope($envelope, 1)->create([
        'name' => 'Fábio Teixeira Ramos',
        'email' => 'fabio.ramos@exemplo.com.br',
        'status' => RecipientStatus::Signed,
    ]);

    Recipient::factory()->forEnvelope($envelope, 2)->create([
        'name' => 'Gabriela Souza Pinto',
        'email' => 'gabriela.pinto@exemplo.com.br',
        'status' => RecipientStatus::Notified,
    ]);

    return [$organization, $owner, $envelope];
}

test('search.index devolve para cada signatário o envelope_id e o envelope_title que a busca ⌘K usa', function () {
    searchFixture();

    $payload = $this->getJson(route('search.index', ['q' => 'Gabriela']))
        ->assertOk()
        ->json();

    expect($payload['recipients'])->toHaveCount(1);

    $recipient = $payload['recipients'][0];

    // command-search.tsx: onSelect={() => openEnvelope(recipient.envelope_id)}
    // Sem `envelope_id` o clique no resultado de signatário quebra (TypeError em route(...).toString()).
    expect($recipient)->toHaveKey('envelope_id');
    expect($recipient['envelope_id'])->toBeString()->not->toBe('');

    // command-search.tsx: "{recipient.email} · {recipient.envelope_title}"
    // Sem `envelope_title` a linha do signatário mostra "email · " e resultados homônimos ficam indistinguíveis.
    expect($recipient)->toHaveKey('envelope_title');
    expect($recipient['envelope_title'])->toBe('Contrato de locação comercial');
});

test('search.index devolve signed_count e folder para o badge de status do documento (DESIGN §5.1)', function () {
    searchFixture();

    $payload = $this->getJson(route('search.index', ['q' => 'Contrato']))
        ->assertOk()
        ->json();

    expect($payload['envelopes'])->toHaveCount(1);

    $envelope = $payload['envelopes'][0];

    expect($envelope['status_label'])->toBe('Em andamento');

    // EnvelopeStatusBadge usa signedCount para escolher entre âmbar (Aguardando)
    // e azul (Em andamento). Ausente ⇒ default 0 ⇒ rótulo azul pintado de âmbar.
    // Sem `signed_count` o badge "Em andamento" sai âmbar (#9a5b00/#fff4e0) em vez de azul (#1257c9/#e8f0fd).
    expect($envelope)->toHaveKey('signed_count');
    expect($envelope['signed_count'])->toBe(1);

    // command-search.tsx renderiza `envelope.folder` ao lado do display_code.
    expect($envelope)->toHaveKey('folder');
});
