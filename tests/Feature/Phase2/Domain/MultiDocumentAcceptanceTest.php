<?php

use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AcceptanceDocument;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Services\Signing\ConsentText;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.3 — um aceite por participante, cobrindo o conjunto de documentos
|--------------------------------------------------------------------------
| O aceite só é gravado se TODOS os documentos foram entregues à sessão e se os campos
| obrigatórios de TODOS os documentos em que a pessoa tem campos foram preenchidos. Por
| documento fica o que foi aceito (versão, SHA-256, campos e valores).
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();

    Event::fake([EnvelopeReadyForFinalization::class]);

    $this->ctx = domainEnvelope(['Contrato', 'Anexo A', 'Anexo B'], [[
        'name' => 'Maria Alves Souza',
        'email' => 'maria@exemplo.test',
        'fields' => [
            ['doc' => 0, 'type' => FieldType::Signature],
            ['doc' => 2, 'type' => FieldType::Text, 'required' => true, 'label' => 'Matrícula'],
        ],
    ]]);

    $this->token = $this->ctx['tokens']['maria@exemplo.test'];
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

function multiTextField(array $props): string
{
    return collect($props['my_fields'])->firstWhere('type', 'text')['id'];
}

it('recusa o aceite enquanto um dos documentos não foi entregue à sessão', function () {
    $props = domainAuthenticate($this, $this->token);

    expect($props['documents'])->toHaveCount(3)
        ->and(collect($props['documents'])->pluck('presented')->all())->toBe([false, false, false]);

    domainPresent($this, $props, [0, 1]);

    domainAccept($this, $this->token, $props, [multiTextField($props) => 'M-2291'])
        ->assertSessionHasErrors(['signature' => 'Abra e confira todos os arquivos antes de assinar: o arquivo "Anexo B" ainda não foi carregado nesta sessão.']);

    expect(SignatureAcceptance::query()->count())->toBe(0);

    // Recebido o terceiro, o mesmo aceite (a tela não mudou) é gravado.
    domainPresent($this, $props, [2]);

    domainAccept($this, $this->token, $props, [multiTextField($props) => 'M-2291'])->assertSessionHasNoErrors();

    expect(SignatureAcceptance::query()->count())->toBe(1)
        ->and(AcceptanceDocument::query()->count())->toBe(3);
});

it('recusa o aceite quando falta o campo obrigatório de um dos documentos', function () {
    $props = domainAuthenticate($this, $this->token);
    domainPresent($this, $props);

    domainAccept($this, $this->token, $props, [])
        ->assertSessionHasErrors(['fields.'.multiTextField($props)]);

    expect(SignatureAcceptance::query()->count())->toBe(0)
        ->and(AcceptanceDocument::query()->count())->toBe(0);
});

it('grava um aceite que cobre o conjunto e registra, por documento, versão, resumo e campos', function () {
    $props = domainAuthenticate($this, $this->token);
    domainPresent($this, $props);

    domainAccept($this, $this->token, $props, [multiTextField($props) => 'M-2291'])->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::query()->sole();
    $versions = $this->ctx['versions'];

    // Colunas da Fase 1 continuam apontando para o primeiro documento.
    expect((int) $acceptance->document_version_id)->toBe((int) $versions[0]->getKey())
        ->and($acceptance->document_sha256)->toBe($versions[0]->sha256)
        ->and($acceptance->action->value)->toBe('sign')
        ->and($acceptance->terms_version)->toBe(ConsentText::MULTI_DOCUMENT_TERMS_VERSION)
        ->and($acceptance->fields_snapshot['schema'])->toBe(2)
        ->and($acceptance->fields_snapshot['documents'])->toHaveCount(3);

    foreach ($versions as $version) {
        expect($acceptance->consent_statement)->toContain($version->sha256);
    }

    $rows = AcceptanceDocument::query()->orderBy('position')->get();

    expect($rows->pluck('position')->all())->toBe([1, 2, 3])
        ->and($rows->pluck('document_sha256')->all())->toBe(array_map(fn ($v) => $v->sha256, $versions))
        ->and($rows->pluck('document_version_id')->map(fn ($id) => (int) $id)->all())->toBe(array_map(fn ($v) => (int) $v->getKey(), $versions))
        ->and(collect($rows[2]->fields_snapshot['values'])->pluck('text')->filter()->values()->all())->toBe(['M-2291'])
        ->and($rows[1]->fields_snapshot['fields'])->toBe([]);

    // Um `document.presented` por documento, e o aceite diz sobre quais documentos foi.
    expect(AuditEvent::query()->where('event_type', AuditEventType::DocumentPresented->value)->count())->toBe(3);

    $event = AuditEvent::query()->where('event_type', AuditEventType::AcceptanceRecorded->value)->sole();

    expect($event->payload['documents'])->toHaveCount(3)
        ->and($event->payload['action'])->toBe('sign');

    Event::assertDispatched(EnvelopeReadyForFinalization::class);
});

it('entrega só documentos deste envelope pelo parâmetro document', function () {
    domainAuthenticate($this, $this->token);

    $other = domainEnvelope(['Outro'], [['name' => 'Ana', 'email' => 'ana@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]]]);

    $this->get(route('sign.document', ['token' => $this->token, 'document' => $other['documents'][0]->ulid]))->assertNotFound();
    $this->get(route('sign.document', ['token' => $this->token, 'document' => $this->ctx['documents'][1]->ulid]))->assertOk();
});
