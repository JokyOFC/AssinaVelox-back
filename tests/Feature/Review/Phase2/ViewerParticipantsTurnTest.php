<?php

use App\Enums\FieldType;
use App\Enums\RecipientRole;
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
| Revisão adversarial Fase 2 onda A (produto/semântica) — vez do visualizador
|--------------------------------------------------------------------------
| O visualizador não tem vez (order_index = 0). Na tela "Cópia para acompanhamento"
| o cartão "Participantes" usa `others[].signs_after_me` e, como todo participante tem
| order_index > 0, TODOS aparecem como "assina depois de você" / "depois de você".
| Visto no navegador (375 px): "Locador Teste · Locador — assina depois de você",
| "Aprovador Teste · Aprovador — depois de você". A frase sugere que o visualizador
| está na fila e que os outros esperam por ele — o contrário da regra §2.4
| ("visualizador … não entra na ordem").
| Origem: app/Services/Signing/SignerPageProps.php::others()
|   'signs_after_me' => $sequential && (int) $r->order_index > $myOrder
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();

    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('não diz ao visualizador que os participantes "assinam depois de você" (ele não tem vez)', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Victor Visualizador', 'email' => 'victor@exemplo.test', 'role' => RecipientRole::Viewer],
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Tadeu Testemunha', 'email' => 'tadeu@exemplo.test', 'role' => RecipientRole::Witness, 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], SigningOrder::Sequential);

    $props = domainAuthenticate($this, $ctx['tokens']['victor@exemplo.test']);

    expect($props['screen'])->toBe('view');

    $afterMe = collect($props['others'])->mapWithKeys(fn (array $other): array => [$other['name'] => $other['signs_after_me']])->all();

    expect($afterMe)->toBe(
        ['Maria Alves' => false, 'Tadeu Testemunha' => false],
        'O visualizador (order_index 0) vê todos como "assina depois de você" na tela de acompanhamento: '
            .json_encode($afterMe, JSON_UNESCAPED_UNICODE)
    );
});
