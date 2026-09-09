<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial final — invariante da máquina de estados do envelope
|--------------------------------------------------------------------------
|
| DEFEITO: `envelopes.signing_order` e `recipients.order_index` só ficam coerentes se
| forem gravados pelo MESMO caminho. Quem grava `order_index` é
| `App\Services\Envelopes\RecipientSync::handle()` (RecipientSync.php:101):
|
|     $orderIndex = $signingOrder === SigningOrder::Sequential ? $position + 1 : 1;
|
| Mas `App\Http\Controllers\Envelopes\EnvelopeController::update()`
| (EnvelopeController.php:312-324) aceita `signing_order` entre os campos de autosave do
| passo 1 e o grava direto no envelope, sem tocar nos destinatários:
|
|     foreach (['title', 'message', 'signing_order'] as $key) { ... }
|     $envelope->forceFill($attributes)->save();
|
| `EnvelopeReadiness::issues()` / `computeStatus()` não conferem essa coerência, e
| `SendEnvelope::commitSend()` também não. O resultado é um envelope gravado como
| `sequential` cujos destinatários têm todos `order_index = 1`.
|
| A partir daí a ordem sequencial deixa de existir, sem que nada acuse:
|
|  - `InvitationDispatcher::pendingForCurrentTurn()` filtra
|    `order_index = current_order` (= 1) e convida TODO MUNDO de uma vez;
|  - `RecordAcceptance::assertStillSignable()` recusa apenas
|    `order_index > current_order`, então qualquer um assina a qualquer momento;
|  - `SignerLinkResolver::stateFor()` devolve `active` para todos.
|
| Enquanto isso, `envelopes.show`, a página de evidências e o relatório impresso continuam
| dizendo "Assinatura em ordem" (resources/js/pages/envelopes/show.tsx:447 e
| evidence.tsx:309, via `signingOrderLabels`) — a plataforma afirma uma garantia de ordem
| que ela não aplicou.
|
| O caminho é o do produto, não um uso exótico do serviço: o próprio wizard chama
| `saveMetadata()` e `saveRecipients()` em autosaves separados
| (resources/js/pages/envelopes/wizard.tsx:397-399), e `saveRecipients()` desiste sem
| enviar nada quando algum signatário ainda está incompleto
| (`if (next.length === 0 || !next.every(recipientIsComplete)) return;`) — o PATCH dos
| metadados sai assim mesmo.
|
| Correção esperada: recomputar `order_index` ao mudar `signing_order` (ou recusar a
| mudança fora do RecipientSync), e a completude do passo 4 acusar a incoerência.
*/

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\Recipient;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerLinkResolver;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    fakeEmailProvider();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $this->organization = $organization;
    $this->owner = $owner;

    // Lista montada em paralelo: todos os destinatários ficam com order_index = 1.
    $this->envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ], SigningOrder::Parallel);

    actingAsMember($owner, $organization);
});

it('não deixa o envelope ficar sequencial com todos os signatários na mesma vez', function () {
    $this->patch(route('envelopes.update', $this->envelope), ['signing_order' => 'sequential'])
        ->assertRedirect();

    $envelope = $this->envelope->fresh();

    expect($envelope->signing_order)->toBe(SigningOrder::Sequential);

    $indexes = $envelope->recipients()->orderBy('id')->pluck('order_index')->all();

    // Em um envelope sequencial cada signatário tem a sua vez: 1, 2, 3…
    expect($indexes)->toBe([1, 2], 'envelope sequencial com signatários empilhados na mesma vez');
});

/*
| AJUSTE DE TESTE (revisão final).
|
| Este caso era escrito sobre o MESMO PATCH do caso anterior: mandava
| `signing_order=sequential` e, logo depois, exigia que `EnvelopeReadiness::issues()`
| acusasse a incoerência. Com a correção do caso anterior — o autosave passa a reindexar
| as vezes (`RecipientSync::reindex()`) — a incoerência deixa de existir naquele ponto, e
| as duas asserções passam a se contradizer: não é possível ao mesmo tempo ter `[1, 2]` e
| ter pendência por causa das vezes.
|
| A garantia que importa continua sendo testada, e agora sem depender do defeito: uma
| linha incoerente gravada por FORA do sync (uma linha antiga, um envelope tocado por
| outro caminho) tem de ser acusada pela tela do passo 4 antes de o envio sair. É a mesma
| razão de existir de `SendEnvelope::commitSend()` reler a lista de pendências sob lock —
| "o status é um resumo gravado; a lista de pendências é a que a tela mostra".
*/
it('acusa a incoerência entre a ordem sequencial e as vezes dos signatários antes de deixar enviar', function () {
    // Envelope gravado como sequencial com os signatários empilhados na mesma vez, sem
    // passar pelo sync — exatamente o estado que o autosave produzia.
    $this->envelope->forceFill(['signing_order' => SigningOrder::Sequential])->save();

    Recipient::withoutOrganizationScope()
        ->where('envelope_id', $this->envelope->getKey())
        ->update(['order_index' => 1]);

    $envelope = $this->envelope->fresh();

    expect($envelope->signing_order)->toBe(SigningOrder::Sequential)
        ->and($envelope->status)->toBe(EnvelopeStatus::Ready)
        ->and($envelope->recipients()->pluck('order_index')->all())->toBe([1, 1]);

    // A tela do passo 4 é a última chance de a plataforma dizer que a ordem prometida não
    // pode ser cumprida.
    expect(EnvelopeReadiness::issues($envelope))->not->toBe([]);

    // E o envio para, porque `commitSend()` relê a mesma lista sob lock.
    expect(fn () => app(SendEnvelope::class)->handle($envelope))
        ->toThrow(SendingException::class);
});

it('acusa a incoerência inversa: ordem em paralelo com signatários em vezes diferentes', function () {
    // `parallel` com 1..N faz `InvitationDispatcher::pendingForCurrentTurn()` convidar só
    // quem está em `current_order` — os demais nunca recebem nada.
    $recipients = $this->envelope->recipients()->orderBy('id')->get();

    foreach ($recipients->values() as $position => $recipient) {
        $recipient->forceFill(['order_index' => $position + 1])->save();
    }

    $envelope = $this->envelope->fresh();

    expect($envelope->signing_order)->toBe(SigningOrder::Parallel)
        ->and(EnvelopeReadiness::issues($envelope))->not->toBe([]);
});

it('reindexa as vezes também quando a ordem volta para paralelo', function () {
    $this->patch(route('envelopes.update', $this->envelope), ['signing_order' => 'sequential'])
        ->assertRedirect();

    expect($this->envelope->fresh()->recipients()->orderBy('id')->pluck('order_index')->all())->toBe([1, 2]);

    $this->patch(route('envelopes.update', $this->envelope), ['signing_order' => 'parallel'])
        ->assertRedirect();

    $envelope = $this->envelope->fresh();

    expect($envelope->signing_order)->toBe(SigningOrder::Parallel)
        ->and($envelope->recipients()->orderBy('id')->pluck('order_index')->all())->toBe([1, 1])
        ->and(EnvelopeReadiness::issues($envelope))->toBe([]);
});

it('convida um signatário por vez em um envelope sequencial, mesmo depois de a ordem mudar pelo autosave', function () {
    $this->patch(route('envelopes.update', $this->envelope), ['signing_order' => 'sequential'])
        ->assertRedirect();

    $envelope = $this->envelope->fresh();

    $result = app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    expect($envelope->signing_order)->toBe(SigningOrder::Sequential)
        ->and($result['invitations'])->toBe(1, 'todos os signatários foram convidados de uma vez em um envelope sequencial');

    $carlos = $envelope->recipients()->where('email', 'carlos@exemplo.com')->firstOrFail();

    // O segundo da fila não pode estar convidado nem apto a assinar antes da vez dele.
    expect($carlos->status)->toBe(RecipientStatus::Pending)
        ->and(SignerLinkResolver::stateFor($envelope, $carlos))->not->toBe(SignerContext::STATE_ACTIVE);
});
