<?php

use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda A (produto/UX) — visualizador "pula" para o topo
|--------------------------------------------------------------------------
| No navegador: no passo 2 cliquei "Adicionar visualizador" (último da lista). Depois do
| autosave, o visualizador passou a ser o PRIMEIRO da lista nos passos 2, 3 ("Por
| participante") e 4, no detalhe do documento e no cartão de participantes da página
| pública — acima do "1º" signatário.
| Causa: RecipientSync grava order_index = 0 no visualizador e Envelope::recipients()
| ordena por order_index (app/Models/Envelope.php:290). O remetente perde a ordem em que
| montou a lista e o item recém-adicionado some do lugar onde ele estava olhando.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();

    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('mantém o visualizador na posição em que o remetente o colocou no passo 2', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    domainEnableFlags($organization);
    setPlanQuota($organization, 5);
    actingAsMember($owner, $organization);

    $ctx = domainEnvelope(['Contrato'], [], SigningOrder::Sequential, $organization, $owner, sent: false);
    $envelope = $ctx['envelope'];

    $this->put(route('envelopes.recipients.sync', ['envelope' => $envelope->ulid]), [
        'signing_order' => 'sequential',
        'recipients' => [
            ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'participant_role' => 'signer'],
            ['name' => 'Tadeu Testemunha', 'email' => 'tadeu@exemplo.test', 'participant_role' => 'witness'],
            ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'participant_role' => 'viewer'],
        ],
    ])->assertSessionHasNoErrors();

    $props = $this->get(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 2]))->viewData('page')['props'];

    expect(array_column($props['recipients'], 'email'))->toBe(
        ['maria@exemplo.test', 'tadeu@exemplo.test', 'victor@exemplo.test'],
        'Depois do autosave o visualizador, adicionado por último, volta como o primeiro da lista.'
    );
});
