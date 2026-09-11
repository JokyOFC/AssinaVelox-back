<?php

use App\Enums\FieldType;
use App\Enums\SigningOrder;
use App\Events\EnvelopeReadyForFinalization;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../Phase2/InPerson/Support/PresenceHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — o lote diz "Não pedimos CPF" e depois pede CPF
|--------------------------------------------------------------------------
| A integração corrigiu o aviso de privacidade por participante (achado da QA: "Não pedimos
| senha, CPF, foto" com CPF exigido). O lote ficou de fora: a tela `identify` do lote — a que o
| participante lê ANTES de pedir o código — usa o aviso genérico do signatário, sem o
| participante (BatchPageProps::build(), app/Services/Batch/BatchPageProps.php:93-97). O lote
| só manda para o link individual os itens com SMS, WhatsApp, PIN ou foto
| (BatchItems::individualOnlyReason()); item com campo CPF é autorizável no lote. Resultado: o
| aviso diz "Não pedimos senha, CPF, foto ou localização." e um dos documentos do mesmo lote
| exige o CPF.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-2b-batch-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    $this->batchCodes = presenceCaptureBatchCodes();
    $this->batchLinks = presenceCaptureBatchLinks();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('o aviso da tela de código do lote nega pedir CPF, mas um item do lote exige CPF', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Imóveis']);
    presenceEnable($organization);

    $one = signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]]], SigningOrder::Parallel, ['title' => 'Contrato de locação'], $organization, $owner);
    $two = signerEnvelope([['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature, FieldType::Cpf]]], SigningOrder::Parallel, ['title' => 'Ficha cadastral'], $organization, $owner);

    batchIssue($this, $organization, $owner, $one['envelope'], array_values($one['recipients'])[0])->assertSessionHas('success');
    batchAsParticipant($this);

    $this->get($this->batchLinks[0])->assertRedirect(route('sign.batch.show'));
    $identify = $this->get(route('sign.batch.show'))->assertOk()->viewData('page')['props'];
    $notice = (string) $identify['privacy']['notice'];

    $list = batchAuthenticate($this, $this->batchLinks[0]);
    $cpfItem = collect($list['items'])->firstWhere('title', 'Ficha cadastral');

    // O item com CPF é autorizável no próprio lote.
    expect($cpfItem['authorizable'])->toBeTrue();

    $current = batchOpen($this, $cpfItem['id']);
    expect(collect($current['my_fields'])->pluck('type')->all())->toContain('cpf');

    expect(str_contains($notice, 'Não pedimos senha, CPF'))->toBeFalse('Aviso exibido antes do código do lote: "Não pedimos senha, CPF, foto ou localização."');
});
