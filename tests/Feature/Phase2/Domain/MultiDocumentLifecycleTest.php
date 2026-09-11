<?php

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\AcceptanceDocument;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\SignatureAcceptance;
use App\Models\VerificationRecord;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Notifications\Signing\SignerOtpNotification;
use App\Services\Signing\ConsentText;
use App\Services\Verification\PublicVerification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Documents/Support/helpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Finalization/Support/FinalizationHelpers.php';
require_once __DIR__.'/Support/DomainHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.3 — envelope com 3 documentos, ponta a ponta, pelas rotas HTTP reais
|--------------------------------------------------------------------------
| Cria, sobe 3 PDFs (a flag `multi_document` ligada acrescenta em vez de substituir),
| posiciona campos em documentos diferentes, envia, autentica, recebe cada documento,
| aceita e finaliza (fila `sync`: a finalização roda dentro do último aceite, contra o
| pdftool real). Confere que cada arquivo tem seu final e seu resumo publicado.
*/

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('app.url', 'https://assinavelox.test');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.trust_roots', []);

    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);
    $this->organization = $organization;
    setPlanQuota($organization, 5);
    domainEnableFlags($organization);

    $this->invites = new ArrayObject;
    $this->codes = new ArrayObject;

    Event::listen(NotificationSending::class, function (NotificationSending $event): void {
        if ($event->notification instanceof RecipientInvitationNotification) {
            $this->invites[$event->notification->recipient->email] = $event->notification->signingUrl;
        }

        if ($event->notification instanceof SignerOtpNotification) {
            $this->codes[] = $event->notification->code;
        }
    });

    actingAsMember($owner, $organization);
});

afterEach(function () {
    cleanupDocumentsWorkspace($this->work ?? null);
});

function multiLifecycleToken(object $test, string $email): string
{
    expect(isset($test->invites[$email]))->toBeTrue("Nenhum convite para {$email}.");

    $segments = array_values(array_filter(explode('/', (string) parse_url((string) $test->invites[$email], PHP_URL_PATH))));

    return end($segments);
}

it('prepara, envia, coleta um aceite por participante cobrindo os 3 documentos, finaliza cada um e conclui só com todos', function () {
    $this->get(route('envelopes.create'))->assertRedirect();

    /** @var Envelope $envelope */
    $envelope = Envelope::query()->latest('id')->firstOrFail();

    uploadDocument($envelope, PdfFixtures::twoPagePdf($this->work.'/contrato.pdf'), 'contrato.pdf')->assertSessionHasNoErrors();
    uploadDocument($envelope->fresh(), PdfFixtures::onePagePdf($this->work.'/anexo-a.pdf', 'Anexo A'), 'anexo-a.pdf')->assertSessionHasNoErrors();
    uploadDocument($envelope->fresh(), PdfFixtures::onePagePdf($this->work.'/anexo-b.pdf', 'Anexo B'), 'anexo-b.pdf')->assertSessionHasNoErrors();

    $documents = Document::query()->where('envelope_id', $envelope->getKey())->orderBy('position')->get();

    expect($documents)->toHaveCount(3)
        ->and($documents->pluck('position')->all())->toBe([1, 2, 3])
        ->and($documents->pluck('original_filename')->all())->toBe(['contrato.pdf', 'anexo-a.pdf', 'anexo-b.pdf'])
        ->and($documents->every(fn (Document $d): bool => $d->processing_status === DocumentProcessingStatus::Ready))->toBeTrue();

    $this->put(route('envelopes.recipients.sync', ['envelope' => $envelope->ulid]), [
        'signing_order' => 'parallel',
        'recipients' => [
            ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'role' => 'Locatária'],
            ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test', 'role' => 'Fiador'],
        ],
    ])->assertSessionHasNoErrors();

    $fields = [];

    foreach ($envelope->recipients()->orderBy('id')->get() as $index => $recipient) {
        $y = 0.30 + ($index * 0.30);

        // Assinatura no contrato, data no anexo A e um texto obrigatório no anexo B.
        $fields[] = ['recipient_id' => $recipient->ulid, 'type' => 'signature', 'page' => 1, 'x' => 0.10, 'y' => $y, 'w' => 0.34, 'h' => 0.08];
        $fields[] = ['recipient_id' => $recipient->ulid, 'document_id' => $documents[1]->ulid, 'type' => 'date', 'page' => 1, 'x' => 0.10, 'y' => $y, 'w' => 0.30, 'h' => 0.05];
        $fields[] = ['recipient_id' => $recipient->ulid, 'document_id' => $documents[2]->ulid, 'type' => 'text', 'page' => 1, 'x' => 0.10, 'y' => $y, 'w' => 0.40, 'h' => 0.05, 'required' => true, 'label' => 'Matrícula'];
    }

    $this->put(route('envelopes.fields.sync', ['envelope' => $envelope->ulid]), [
        'initials_on_all_pages' => false,
        'fields' => $fields,
    ])->assertSessionHasNoErrors();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);

    $this->post(route('envelopes.send', ['envelope' => $envelope->ulid]))->assertSessionHasNoErrors();

    $sent = $envelope->fresh();
    $documents = $documents->map->fresh();

    // Cada documento congelou a SUA versão; o envelope guarda a do primeiro.
    expect($sent->status)->toBe(EnvelopeStatus::InProgress)
        ->and($documents->every(fn (Document $d): bool => $d->sent_version_id !== null && $d->sent_version_id === $d->current_version_id))->toBeTrue()
        ->and((int) $sent->sent_document_version_id)->toBe((int) $documents[0]->sent_version_id);

    foreach (['maria@exemplo.test' => 'M-2291', 'henrique@exemplo.test' => 'H-8814'] as $email => $matricula) {
        $token = multiLifecycleToken($this, $email);
        $props = domainAuthenticate($this, $token);

        expect($props['screen'])->toBe('sign')
            ->and($props['documents'])->toHaveCount(3)
            ->and($props['consent']['version'])->toBe(ConsentText::MULTI_DOCUMENT_TERMS_VERSION)
            ->and(collect($props['my_fields'])->pluck('document_id')->unique()->values()->all())
            ->toBe($documents->pluck('ulid')->all());

        foreach ($documents as $document) {
            expect($props['consent_text'])->toContain($document->fresh()->sentVersion->sha256);
        }

        domainPresent($this, $props);

        $values = [];

        foreach ($props['my_fields'] as $field) {
            if ($field['type'] === 'text') {
                $values[$field['id']] = $matricula;
            }
        }

        domainAccept($this, $token, $props, $values)->assertSessionHasNoErrors();

        $acceptance = SignatureAcceptance::query()->where('recipient_id', $sent->recipients()->where('email', $email)->value('id'))->firstOrFail();

        expect(AcceptanceDocument::query()->where('signature_acceptance_id', $acceptance->getKey())->count())->toBe(3);
    }

    $completed = $envelope->fresh();

    expect($completed->status)->toBe(EnvelopeStatus::Completed);

    $record = VerificationRecord::query()->where('envelope_id', $completed->getKey())->firstOrFail();
    $children = $record->documents()->get();

    expect($children)->toHaveCount(3);

    foreach ($documents->map->fresh() as $index => $document) {
        // Um consolidado, uma página de evidências e um final POR documento.
        foreach ([DocumentVersionKind::Consolidated, DocumentVersionKind::Evidence, DocumentVersionKind::Final] as $kind) {
            expect(domainVersionsOfKind($document, $kind))->toHaveCount(1);
        }

        $final = $document->finalVersion;
        $bytes = (string) Storage::disk('documents')->get($final->storage_path);

        // O resumo publicado identifica os bytes entregues, arquivo por arquivo.
        expect($final->kind)->toBe(DocumentVersionKind::Final)
            ->and(hash('sha256', $bytes))->toBe($children[$index]->final_sha256)
            ->and($children[$index]->document_id)->toBe($document->getKey());
    }

    expect((int) $completed->final_document_version_id)->toBe((int) $documents[0]->fresh()->final_version_id);

    $public = $this->get(route('verify.show', ['code' => $completed->verification_code]))->viewData('page')['props'];

    expect($public['found'])->toBeTrue()
        ->and($public['result']['documents'])->toHaveCount(3)
        ->and(collect($public['result']['documents'])->pluck('final_sha256')->all())->toBe($children->pluck('final_sha256')->all());

    // A conferência pública reconhece o arquivo 2 pelo resumo, e deixa de reconhecê-lo com 1 byte trocado.
    $second = (string) Storage::disk('documents')->get($documents[1]->fresh()->finalVersion->storage_path);
    $tampered = $second;
    $tampered[200] = chr(ord($tampered[200]) ^ 0x01);

    $check = app(PublicVerification::class)->checkHash($completed->fresh(), hash('sha256', $second));
    $checkTampered = app(PublicVerification::class)->checkHash($completed->fresh(), hash('sha256', $tampered));

    expect($check['matches'])->toBe('signed')
        ->and($check['document']['position'])->toBe(2)
        ->and(hash('sha256', $tampered))->not->toBe(hash('sha256', $second))
        ->and($checkTampered['matches'])->toBe('none');
});
