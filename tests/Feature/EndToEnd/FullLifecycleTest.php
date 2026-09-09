<?php

use App\Enums\AuditEventType;
use App\Enums\CertificateEnvironment;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\Subscription;
use App\Models\VerificationRecord;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Notifications\Signing\SignerOtpNotification;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Finalization/Support/FinalizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta COMPLETO: do rascunho ao arquivo final verificável (I3)
|--------------------------------------------------------------------------
| Este arquivo é a costura dos incrementos 2, 3, 4 e 5. Ele estende
| `PreparationAndSigningTest`, que parava em `finalizing` porque o incremento 4
| ainda não existia, e vai até onde o produto de fato termina: arquivo final no
| disco, hashes registrados, página pública respondendo pelo código e cota do
| plano confirmada.
|
| Duas regras valem em todos os cenários:
|
| 1. **Nada de atalho de fábrica no caminho do usuário.** Criar, subir o PDF,
|    posicionar campos, enviar, autenticar, aceitar, baixar e verificar passam
|    pelas rotas HTTP reais. O que é montado direto no banco é só o que o usuário
|    não faz (o certificado da operadora, por exemplo).
| 2. **A semântica de assinatura é asserção, não comentário** (arquitetura §2).
|    Com certificado, o PDF tem UMA assinatura validável e a interface diz
|    "assinado pela operadora"; sem certificado, o PDF não tem assinatura
|    nenhuma e nenhuma tela pode dizer "assinatura digital".
|
| A finalização roda de verdade: com `QUEUE_CONNECTION=sync` o
| `EnvelopeReadyForFinalization` despacha `FinalizeEnvelope` dentro da própria
| requisição do último aceite. É o mesmo código que o worker executaria.
*/

const LIFECYCLE_CERT_PASS_ENV = 'LIFECYCLE_TEST_CERT_PASS';

const LIFECYCLE_ROWS = [
    ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'role' => 'Locatária'],
    ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test', 'role' => 'Fiador'],
];

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('app.url', 'https://assinavelox.test');

    // Sem certificado por padrão: o cenário que quer assinatura liga o dele.
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.trust_roots', []);

    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);
    $this->organization = $organization;
    $this->owner = $owner;

    setPlanQuota($organization, 5);

    // O convite e o código só existem em claro dentro da notificação. Capturamos os dois
    // pelo evento de envio, sem `Notification::fake()`, para que o canal rastreado rode de
    // verdade e as linhas de `delivery_attempts` continuem sendo criadas.
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
    putenv(LIFECYCLE_CERT_PASS_ENV);
    cleanupDocumentsWorkspace($this->work ?? null);
});

// -- Passos do ciclo, cada um por HTTP ---------------------------------------------

/**
 * Campos de verdade: cada destinatário recebe assinatura + texto livre + data
 * carimbada pelo servidor, em retângulos que não se sobrepõem. Os valores de texto
 * e data são o que os testes procuram DENTRO do retângulo no PDF final.
 */
function lifecycleSyncFields(object $test, Envelope $envelope): void
{
    $recipients = $envelope->recipients()->orderBy('order_index')->get();
    $fields = [];

    foreach ($recipients as $index => $recipient) {
        $base = 0.30 + ($index * 0.28);

        $fields[] = [
            'client_id' => "sig-{$index}",
            'recipient_id' => $recipient->ulid,
            'type' => 'signature',
            'page' => 1,
            'x' => 0.10, 'y' => $base, 'w' => 0.34, 'h' => 0.08,
            'required' => true,
        ];

        $fields[] = [
            'client_id' => "txt-{$index}",
            'recipient_id' => $recipient->ulid,
            'type' => 'text',
            'page' => 1,
            'x' => 0.52, 'y' => $base, 'w' => 0.36, 'h' => 0.05,
            'required' => true,
            'label' => 'Matrícula',
        ];

        // Segunda página: prova que a consolidação respeita a página do campo.
        $fields[] = [
            'client_id' => "dat-{$index}",
            'recipient_id' => $recipient->ulid,
            'type' => 'date',
            'page' => 2,
            'x' => 0.10, 'y' => $base, 'w' => 0.30, 'h' => 0.05,
            'required' => true,
        ];
    }

    $test->put(route('envelopes.fields.sync', ['envelope' => $envelope->ulid]), [
        'initials_on_all_pages' => false,
        'fields' => $fields,
    ])->assertSessionHasNoErrors();
}

/**
 * Wizard completo por HTTP: cria, sobe um PDF real de 2 páginas, sincroniza
 * destinatários e campos. Devolve o envelope em `ready`.
 *
 * @param  list<array{name: string, email: string, role?: string}>  $rows
 */
function lifecyclePrepared(object $test, array $rows, SigningOrder $order = SigningOrder::Sequential): Envelope
{
    $test->get(route('envelopes.create'))->assertRedirect();

    /** @var Envelope $envelope */
    $envelope = Envelope::query()->latest('id')->firstOrFail();
    expect($envelope->status)->toBe(EnvelopeStatus::Draft);

    $source = PdfFixtures::twoPagePdf($test->work.DIRECTORY_SEPARATOR.'contrato-'.$envelope->ulid.'.pdf');
    uploadDocument($envelope, $source, 'contrato.pdf')->assertSessionHasNoErrors();

    /** @var Document $document */
    $document = Document::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    expect($document->processing_status)->toBe(DocumentProcessingStatus::Ready)
        ->and((int) $document->page_count)->toBe(2);

    $payload = [];

    foreach (array_values($rows) as $index => $row) {
        $payload[] = [
            'client_id' => 'tmp-'.$index,
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'] ?? null,
            'order' => $index + 1,
        ];
    }

    $test->put(route('envelopes.recipients.sync', ['envelope' => $envelope->ulid]), [
        'signing_order' => $order->value,
        'recipients' => $payload,
    ])->assertSessionHasNoErrors();

    lifecycleSyncFields($test, $envelope->fresh());

    $ready = $envelope->fresh();
    expect($ready->status)->toBe(EnvelopeStatus::Ready);

    return $ready;
}

/** Envio: congela a versão, gera o código de verificação e reserva a cota. */
function lifecycleSend(object $test, Envelope $envelope): Envelope
{
    $test->post(route('envelopes.send', ['envelope' => $envelope->ulid]))->assertSessionHasNoErrors();

    $sent = $envelope->fresh();

    expect($sent->status)->toBe(EnvelopeStatus::InProgress)
        ->and($sent->sent_document_version_id)->not->toBeNull()
        ->and($sent->verification_code)->not->toBeNull();

    return $sent;
}

/** Token bruto do convite (só existe dentro da notificação). */
function lifecycleToken(object $test, string $email): string
{
    expect(isset($test->invites[$email]))->toBeTrue("Nenhum convite foi despachado para {$email}.");

    $path = (string) parse_url((string) $test->invites[$email], PHP_URL_PATH);
    $segments = array_values(array_filter(explode('/', $path)));

    return end($segments);
}

/**
 * Autentica pelo caminho real (pedir código → ler o código do e-mail → verificar).
 *
 * @return array<string, mixed> props da tela `sign`
 */
function lifecycleAuthenticate(object $test, string $token): array
{
    $test->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();

    $codes = $test->codes;
    $test->post(route('sign.otp.verify', ['token' => $token]), [
        'code' => $codes[count($codes) - 1],
    ])->assertRedirect();

    $props = $test->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
    expect($props['screen'])->toBe('sign');

    return $props;
}

/**
 * Aceite com assinatura desenhada e os campos preenchidos.
 *
 * @param  array<string, mixed>  $props
 */
function lifecycleAccept(object $test, string $token, array $props, string $matricula): void
{
    $fields = [];

    foreach ($props['my_fields'] as $field) {
        if ($field['type'] === 'text') {
            $fields[$field['id']] = $matricula;
        }

        // `date` é carimbado pelo servidor (RECONCILIACAO Q9): mandamos lixo de
        // propósito para provar que o servidor ignora o que veio do cliente.
        if ($field['type'] === 'date') {
            $fields[$field['id']] = '01/01/1970';
        }
    }

    $test->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        'fields' => $fields,
    ], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (E2E AssinaVelox)'])->assertSessionHasNoErrors();
}

/** Percorre os dois aceites e devolve o envelope depois da finalização. */
function lifecycleCollectBothAcceptances(object $test, Envelope $envelope): Envelope
{
    $mariaToken = lifecycleToken($test, 'maria@exemplo.test');
    lifecycleAccept($test, $mariaToken, lifecycleAuthenticate($test, $mariaToken), 'M-2291');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);

    $henriqueToken = lifecycleToken($test, 'henrique@exemplo.test');
    lifecycleAccept($test, $henriqueToken, lifecycleAuthenticate($test, $henriqueToken), 'H-8814');

    return $envelope->fresh();
}

/** Liga o certificado A1 de TESTE da operadora e o registra. */
function lifecycleEnableTestCertificate(object $test): array
{
    putenv(LIFECYCLE_CERT_PASS_ENV.'=senha-de-teste-Rt71!');

    $certificate = TestCertificate::generate(
        $test->work.DIRECTORY_SEPARATOR.'certs',
        LIFECYCLE_CERT_PASS_ENV,
        TestCertificate::SUBJECT,
        2,
    );

    TestCertificate::configure($certificate);
    TestCertificate::register($certificate);

    return $certificate;
}

/** Texto corrido de um PDF, com o espaço em branco normalizado (o DOMPDF quebra linhas). */
function lifecyclePdfText(string $path): string
{
    $text = '';

    foreach (finalizationPdfPages($path) as $page) {
        foreach ($page['runs'] as $run) {
            $text .= ' '.(string) $run['text'];
        }
    }

    return (string) preg_replace('/\s+/u', ' ', $text);
}

// -- Cenário 1: ciclo completo COM certificado da operadora -------------------------

it('vai do rascunho ao arquivo final assinado pela operadora, verificável e com a cota confirmada', function () {
    $certificate = lifecycleEnableTestCertificate($this);

    $envelope = lifecyclePrepared($this, LIFECYCLE_ROWS);
    $envelope = lifecycleSend($this, $envelope);

    $sentVersionId = $envelope->sent_document_version_id;
    $code = (string) $envelope->verification_code;

    $envelope = lifecycleCollectBothAcceptances($this, $envelope);

    // -- a) o envelope concluiu de verdade ---------------------------------------
    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->completed_at)->not->toBeNull()
        ->and($envelope->final_document_version_id)->not->toBeNull()
        // A versão congelada no envio não mudou: os aceites são sobre ela.
        ->and($envelope->sent_document_version_id)->toBe($sentVersionId)
        ->and(SignatureAcceptance::query()->count())->toBe(2)
        ->and(SignatureAcceptance::query()->pluck('document_version_id')->unique()->all())->toBe([$sentVersionId]);

    // As quatro versões existem, na ordem do pipeline (arquitetura §5, item 7).
    $kinds = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $envelope->document->getKey())
        ->orderBy('version_number')
        ->pluck('kind')
        ->map(fn (DocumentVersionKind $kind): string => $kind->value)
        ->all();

    expect($kinds)->toBe(['original', 'consolidated', 'evidence', 'final']);

    // -- b) o arquivo final existe e carrega os valores autorizados ---------------
    $finalVersion = $envelope->fresh()->finalVersion;
    $path = finalizationDownload($finalVersion, $this->work.DIRECTORY_SEPARATOR.'final-assinado.pdf');

    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(1000);

    $pages = finalizationPdfPages($path);
    $text = lifecyclePdfText($path);

    // O documento original (2 páginas) mais a página de evidências.
    expect(count($pages))->toBeGreaterThanOrEqual(3);

    // Cada valor caiu DENTRO do retângulo do campo dele, na página dele.
    $textFields = SigningField::withoutOrganizationScope()
        ->where('envelope_id', $envelope->getKey())
        ->where('type', 'text')
        ->with('recipient')
        ->get();

    foreach ($textFields as $field) {
        $expected = $field->recipient->email === 'maria@exemplo.test' ? 'M-2291' : 'H-8814';

        expect(finalizationTextIsInsideField($pages, $field, $expected))
            ->toBeTrue("A matrícula {$expected} não caiu dentro do retângulo do campo de {$field->recipient->name}.");
    }

    // A data é a do servidor, não a que o cliente mandou.
    expect($text)->not->toContain('01/01/1970');

    $today = now($this->organization->timezone)->format('d/m/Y');
    $dateFields = SigningField::withoutOrganizationScope()
        ->where('envelope_id', $envelope->getKey())
        ->where('type', 'date')
        ->get();

    foreach ($dateFields as $field) {
        expect($field->page)->toBe(2)
            ->and(finalizationTextIsInsideField($pages, $field, $today))->toBeTrue();
    }

    // Rodapé de verificação em todas as páginas do documento consolidado.
    $formatted = implode('-', str_split($code, 4));
    expect($text)->toContain($formatted);

    // -- c) a página de evidências está anexada ----------------------------------
    expect($text)->toContain('Página de evidências do aceite eletrônico')
        ->and($text)->toContain('Maria Alves Souza')
        ->and($text)->toContain('Henrique Dias')
        // A tabela de resumos existe, mas o resumo FINAL fica em branco ali dentro.
        ->and($text)->toContain('Como ler os resumos criptográficos');

    // -- d) UMA assinatura, íntegra, válida e confiável com o certificado de teste --
    $client = app(PdfToolClient::class);
    $inspection = $client->inspect($path);

    expect($inspection->hasSignatures)->toBeTrue()
        ->and($inspection->signatureCount)->toBe(1);

    $validation = $client->validate($path, [$certificate['pem']]);

    expect($validation->signatureCount)->toBe(1)
        ->and($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue()
        ->and($validation->allTrusted())->toBeTrue()
        ->and($validation->signatures[0]->coverage)->toBe('ENTIRE_FILE')
        ->and($validation->signatures[0]->signerSubject)->toContain('TESTE');

    // -- e) registro de verificação com os quatro resumos -------------------------
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->sole();

    $original = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $envelope->document->getKey())
        ->where('kind', DocumentVersionKind::Original->value)->sole();
    $consolidated = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $envelope->document->getKey())
        ->where('kind', DocumentVersionKind::Consolidated->value)->sole();

    expect($record->code)->toBe($code)
        ->and($record->signature_status)->toBe(SignatureStatus::CompanyA1)
        ->and($record->signature_profile)->toBe('PAdES-B-B')
        ->and($record->certificate_reference_id)->not->toBeNull()
        ->and($record->original_sha256)->toBe($original->sha256)
        ->and($record->sent_sha256)->toBe(DocumentVersion::withoutOrganizationScope()->findOrFail($sentVersionId)->sha256)
        ->and($record->consolidated_sha256)->toBe($consolidated->sha256)
        ->and($record->final_sha256)->toBe($finalVersion->sha256)
        // O hash final é o do arquivo DEPOIS da assinatura, e bate byte a byte.
        ->and($record->final_sha256)->toBe(hash_file('sha256', $path))
        // ...e não está impresso dentro do próprio PDF (seria impossível).
        ->and($text)->not->toContain($record->final_sha256);

    // -- f) a página pública encontra o código e não vaza dado pessoal ------------
    $this->post(route('logout'));

    $public = $this->get(route('verify.show', ['code' => $code]));
    $public->assertOk();

    $props = $public->viewData('page')['props'];
    $result = $props['result'];

    expect($props['found'])->toBeTrue()
        ->and($result['verification_code'])->toBe($formatted)
        ->and($result['status'])->toBe('completed')
        ->and($result['signature_status'])->toBe('company_a1')
        ->and($result['hashes']['final_sha256'])->toBe($record->final_sha256)
        ->and($result['hashes']['sent_sha256'])->toBe($record->sent_sha256)
        // Certificado de TESTE aparece como teste, nunca como ICP-Brasil.
        ->and($result['certificate']['is_test'])->toBeTrue()
        ->and($result['certificate']['environment'])->toBe(CertificateEnvironment::Test->value);

    /*
     * O resultado técnico é lido do MESMO lugar em que a finalização o grava
     * (`validation_result.result`). Este bloco existe porque as duas metades foram
     * escritas em paralelo e divergiram: a leitura pegava o nível de cima e toda
     * conclusão assinada aparecia como "integridade não verificada" — conservador,
     * mas falso.
     */
    expect($result['validation']['available'])->toBeTrue()
        ->and($result['validation']['signature_count'])->toBe(1)
        ->and($result['validation']['integrity'])->toBe('intact')
        // A raiz de confiança do certificado de teste NÃO está configurada em produção;
        // aqui ela está, e o resultado diz isso sem exagerar.
        ->and($result['validation']['chain_trust'])->toBe('trusted')
        ->and($result['validation']['revocation'])->toBe('not_checked')
        ->and($result['validation_summary'])->toContain('assinatura íntegra e válida na conclusão')
        ->and($result['validation_summary'])->toContain('revogação não verificada');

    $serialized = (string) json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($serialized)
        ->not->toContain('maria@exemplo.test')
        ->and($serialized)->not->toContain('henrique@exemplo.test')
        ->and($serialized)->not->toContain('Maria Alves Souza')
        ->and($serialized)->not->toContain('Henrique Dias')
        ->and($serialized)->not->toContain('Mozilla/5.0')
        ->and($serialized)->not->toContain('127.0.0.1')
        ->and($serialized)->not->toContain('M-2291')
        ->and($serialized)->not->toContain($envelope->display_code)
        // O resumo do arquivo consolidado não é publicado (só enviado e final).
        ->and($serialized)->not->toContain($record->consolidated_sha256);

    // -- g) download: autorizado baixa, não autorizado não ------------------------
    $anonymous = $this->get(route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'signed']));
    expect($anonymous->getStatusCode())->toBeIn([302, 401, 403, 404]);

    ['organization' => $outsiderOrg, 'owner' => $outsider] = createOrganizationWithOwner(['name' => 'Outra Casa']);
    actingAsMember($outsider, $outsiderOrg);

    $this->get(route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'signed']))
        ->assertNotFound();

    actingAsMember($this->owner, $this->organization);

    $download = $this->get(route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'signed']));
    $download->assertOk();

    expect(hash('sha256', $download->streamedContent()))->toBe($record->final_sha256);

    $this->get(route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'evidence']))->assertOk();

    // -- h) a cota foi confirmada UMA vez ----------------------------------------
    $consumptions = PlanConsumption::withoutOrganizationScope()->where('envelope_id', $envelope->getKey())->get();

    expect($consumptions)->toHaveCount(1)
        ->and($consumptions->first()->status)->toBe(PlanConsumptionStatus::Committed)
        ->and($consumptions->first()->committed_at)->not->toBeNull();

    /** @var Subscription $subscription */
    $subscription = Subscription::withoutOrganizationScope()->where('organization_id', $this->organization->getKey())->sole();

    expect((int) $subscription->envelopes_used)->toBe(1)
        ->and((int) $subscription->envelopes_reserved)->toBe(0);

    // -- i) trilha completa até a conclusão --------------------------------------
    $types = AuditEvent::withoutOrganizationScope()
        ->where('envelope_id', $envelope->getKey())
        ->pluck('event_type')
        ->map(fn (AuditEventType $type): string => $type->value)
        ->all();

    expect($types)->toContain(
        AuditEventType::EnvelopeSent->value,
        AuditEventType::AcceptanceRecorded->value,
        AuditEventType::EnvelopeFinalizing->value,
        AuditEventType::EnvelopeConsolidated->value,
        AuditEventType::EnvelopeEvidenceGenerated->value,
        AuditEventType::EnvelopeSignedCompanyA1->value,
        AuditEventType::EnvelopeCompleted->value,
        AuditEventType::PlanConsumptionCommitted->value,
    )->and($types)->not->toContain(AuditEventType::EnvelopeFinalizationFailed->value);
});

// -- Cenário 2: ciclo completo SEM certificado ------------------------------------

it('conclui sem certificado como aceite eletrônico com evidências, sem assinatura nenhuma no PDF', function () {
    // Nada de certificado: é o estado padrão do `beforeEach`.
    expect(config('pdftool.company_certificate.enabled'))->toBeFalse();

    $envelope = lifecyclePrepared($this, LIFECYCLE_ROWS, SigningOrder::Parallel);
    $envelope = lifecycleSend($this, $envelope);

    $code = (string) $envelope->verification_code;
    $envelope = lifecycleCollectBothAcceptances($this, $envelope);

    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $path = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final-sem-cert.pdf');

    // ZERO assinaturas no arquivo: nada é simulado (arquitetura §2).
    $inspection = app(PdfToolClient::class)->inspect($path);

    expect($inspection->hasSignatures)->toBeFalse()
        ->and($inspection->signatureCount)->toBe(0);

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->sole();

    expect($record->signature_status)->toBe(SignatureStatus::None)
        ->and($record->signature_profile)->toBeNull()
        ->and($record->certificate_reference_id)->toBeNull()
        ->and($record->final_sha256)->toBe(hash_file('sha256', $path));

    // -- A interface diz exatamente o que aconteceu, em todas as três telas -------
    $detail = $this->get(route('envelopes.show', ['envelope' => $envelope->ulid]))->viewData('page')['props'];

    expect($detail['envelope']['signature_status'])->toBe('none')
        ->and($detail['envelope']['certificate'])->toBeNull();

    $evidence = $this->get(route('envelopes.evidence', ['envelope' => $envelope->ulid]))->viewData('page')['props'];

    expect($evidence['signature_status'])->toBe('none')
        ->and($evidence['signature']['label'])->toBe('Aceite eletrônico com evidências')
        ->and($evidence['signature']['statement'])->toContain('sem assinatura criptográfica')
        ->and($evidence['certificate'])->toBeNull();

    $this->post(route('logout'));

    $public = $this->get(route('verify.show', ['code' => $code]));
    $public->assertOk();

    $result = $public->viewData('page')['props']['result'];

    expect($result['signature_status'])->toBe('none')
        ->and($result['status_label'])->toContain('aceite eletrônico com evidências')
        ->and($result['certificate'])->toBeNull();

    // Nenhuma das três telas, nem o PDF, afirma assinatura digital.
    $surfaces = [
        'detalhe' => (string) json_encode($detail, JSON_UNESCAPED_UNICODE),
        'evidencias' => (string) json_encode($evidence, JSON_UNESCAPED_UNICODE),
        'publica' => (string) json_encode($public->viewData('page')['props'], JSON_UNESCAPED_UNICODE),
        'pdf' => lifecyclePdfText($path),
    ];

    /*
     * As expressões proibidas podem aparecer numa NEGAÇÃO — e uma delas aparece de
     * propósito: o relatório de evidências diz, na variante sem certificado, que "nenhuma
     * indicação de 'assinatura digital' deve ser esperada em leitores de PDF". Essa frase é
     * exatamente o que a semântica exige, então ela é removida antes da varredura — e
     * **conferida como presente** antes de ser removida, para que a remoção não possa
     * mascarar uma regressão.
     */
    $negations = [
        'pdf' => ['nenhuma indicação de “assinatura digital” deve ser esperada em leitores de pdf'],
    ];

    foreach ($surfaces as $where => $content) {
        $lowered = mb_strtolower($content);

        foreach ($negations[$where] ?? [] as $negation) {
            $this->assertStringContainsString(
                $negation,
                $lowered,
                "a negativa esperada sumiu de '{$where}'; reveja a lista antes de removê-la da varredura.",
            );

            $lowered = str_replace($negation, '', $lowered);
        }

        /*
         * Uma agulha por asserção, e por `assertStringNotContainsString`: `toContain()` do
         * Pest é VARIÁDICO — o segundo argumento é outra agulha, não mensagem —, e sob
         * `not` a expectativa passa assim que qualquer uma das agulhas faltar. Escrita com
         * "mensagem", esta varredura passava com a agulha proibida presente
         * (tests/Feature/Review/VacuousNegativeAssertionTest.php).
         */
        $this->assertStringNotContainsString('assinado digitalmente', $lowered, "'{$where}' afirma assinatura digital sem certificado.");
        $this->assertStringNotContainsString('assinatura digital', $lowered, "'{$where}' afirma assinatura digital sem certificado.");
        $this->assertStringNotContainsString('icp-brasil', $lowered, "'{$where}' cita ICP-Brasil sem certificado.");
    }
});

// -- Cenário 3: recusa -------------------------------------------------------------

it('encerra por recusa sem arquivo final, sem registro de verificação e sem vazar o motivo em público', function () {
    $envelope = lifecyclePrepared($this, LIFECYCLE_ROWS);
    $envelope = lifecycleSend($this, $envelope);

    $code = (string) $envelope->verification_code;
    $token = lifecycleToken($this, 'maria@exemplo.test');
    lifecycleAuthenticate($this, $token);

    $this->post(route('sign.refuse', ['token' => $token]), [
        'reason' => 'A cláusula 7 não corresponde ao que combinamos por e-mail.',
    ])->assertSessionHasNoErrors();

    $refused = $envelope->fresh();

    expect($refused->status)->toBe(EnvelopeStatus::Refused)
        ->and($refused->final_document_version_id)->toBeNull()
        ->and($refused->completed_at)->toBeNull()
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->exists())->toBeFalse()
        ->and(Recipient::query()->where('email', 'maria@exemplo.test')->sole()->status)->toBe(RecipientStatus::Refused);

    // Nenhuma versão consolidada, de evidências ou final foi criada.
    $kinds = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $envelope->document->getKey())
        ->pluck('kind')
        ->map(fn (DocumentVersionKind $kind): string => $kind->value)
        ->all();

    expect($kinds)->toBe(['original']);

    // A cota reservada foi devolvida ou permanece confirmada uma única vez — o que
    // não pode acontecer é contar duas vezes.
    expect(PlanConsumption::withoutOrganizationScope()->where('envelope_id', $envelope->getKey())->count())->toBe(1);

    // A página pública responde sem arquivo, sem hash final e sem o motivo da recusa.
    $this->post(route('logout'));

    $public = $this->get(route('verify.show', ['code' => $code]));
    $public->assertOk();

    $props = $public->viewData('page')['props'];
    $serialized = (string) json_encode($props, JSON_UNESCAPED_UNICODE);

    expect($props['found'])->toBeTrue()
        ->and($props['result']['status'])->toBe('refused')
        ->and($props['result']['hashes']['final_sha256'])->toBeNull()
        ->and($serialized)->not->toContain('cláusula 7')
        ->and($serialized)->not->toContain('maria@exemplo.test')
        ->and($serialized)->not->toContain('Maria Alves Souza');
});

// -- Cenário 4: expiração ----------------------------------------------------------

it('expira o envelope no prazo, encerra a coleta e não gera arquivo final', function () {
    $envelope = lifecyclePrepared($this, LIFECYCLE_ROWS);
    $envelope = lifecycleSend($this, $envelope);

    $code = (string) $envelope->verification_code;

    // A primeira assina; a segunda deixa o prazo correr.
    $mariaToken = lifecycleToken($this, 'maria@exemplo.test');
    lifecycleAccept($this, $mariaToken, lifecycleAuthenticate($this, $mariaToken), 'M-2291');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);

    // O prazo passa. O comando agendado é quem encerra (RECONCILIACAO Q22).
    $envelope->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->artisan('envelopes:expire')->assertExitCode(0);

    $expired = $envelope->fresh();

    expect($expired->status)->toBe(EnvelopeStatus::Expired)
        ->and($expired->expired_at)->not->toBeNull()
        ->and($expired->final_document_version_id)->toBeNull()
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->exists())->toBeFalse();

    // O aceite já registrado continua existindo — expirar não apaga evidência.
    expect(SignatureAcceptance::query()->count())->toBe(1);

    /*
     * Quem ainda não tinha assinado não assina mais. A tela é `invalid`, não `expired`:
     * `ExpireEnvelopes` REVOGA os links de quem não assinou, e revogação é definitiva e
     * indistinguível de token desconhecido por decisão de `SignerLinkResolver` — um link
     * revogado não pode confirmar que o documento existiu. Quem já assinou mantém o link
     * e continua vendo o próprio comprovante.
     */
    $henriqueToken = lifecycleToken($this, 'henrique@exemplo.test');
    $screen = $this->get(route('sign.show', ['token' => $henriqueToken]))->viewData('page')['props'];

    expect($screen['screen'])->toBe('invalid')
        ->and($screen['envelope'])->toBeNull();

    /*
     * Maria já tinha assinado: o link dela sobrevive e ela continua vendo o comprovante.
     * O `screen` continua sendo `already_signed_pending_others` (a intenção é dar acesso
     * ao comprovante), mas o comprovante NÃO pode prometer arquivo final — daí
     * `receipt.collection_closed`, que a tela usa para dizer que a coleta foi encerrada.
     */
    $mariaScreen = $this->get(route('sign.show', ['token' => $mariaToken]))->viewData('page')['props'];

    expect($mariaScreen['screen'])->not->toBe('sign')
        ->and($mariaScreen['receipt']['collection_closed'])->toBe('expired')
        ->and($mariaScreen['receipt']['final_pdf_available'])->toBeFalse();

    $types = AuditEvent::withoutOrganizationScope()
        ->where('envelope_id', $envelope->getKey())
        ->pluck('event_type')
        ->map(fn (AuditEventType $type): string => $type->value)
        ->all();

    expect($types)->toContain(AuditEventType::EnvelopeExpired->value)
        ->and($types)->not->toContain(AuditEventType::EnvelopeCompleted->value);

    $this->post(route('logout'));

    $public = $this->get(route('verify.show', ['code' => $code]));
    $public->assertOk();

    expect($public->viewData('page')['props']['result']['status'])->toBe('expired');
});

// -- Cenário 5: o arquivo final é conferível pelo resumo ---------------------------

it('publica um resumo final que confere com o arquivo e muda com um único byte alterado', function () {
    lifecycleEnableTestCertificate($this);

    $envelope = lifecyclePrepared($this, [LIFECYCLE_ROWS[0]]);
    $envelope = lifecycleSend($this, $envelope);

    $code = (string) $envelope->verification_code;
    $token = lifecycleToken($this, 'maria@exemplo.test');
    lifecycleAccept($this, $token, lifecycleAuthenticate($this, $token), 'M-2291');

    $envelope = $envelope->fresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Completed);

    $path = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'confere.pdf');
    $published = (string) VerificationRecord::query()
        ->where('envelope_id', $envelope->getKey())->sole()->final_sha256;

    // O arquivo que o usuário baixa tem exatamente o resumo publicado.
    expect(hash_file('sha256', $path))->toBe($published);

    // A rota de conferência aceita o resumo (nunca o arquivo) e reconhece o dele.
    $this->post(route('logout'));

    $this->post(route('verify.check_file', ['code' => $code]), ['sha256' => $published])
        ->assertRedirect();

    $checked = $this->get(route('verify.show', ['code' => $code]))->viewData('page')['props'];
    expect($checked['file_check']['matches'] ?? null)->toBe('signed')
        ->and($checked['file_check']['checked_sha256'] ?? null)->toBe($published);

    // Um byte alterado quebra a conferência.
    $tampered = $this->work.DIRECTORY_SEPARATOR.'adulterado.pdf';
    $bytes = (string) file_get_contents($path);
    $bytes[(int) (strlen($bytes) / 2)] = $bytes[(int) (strlen($bytes) / 2)] === 'A' ? 'B' : 'A';
    file_put_contents($tampered, $bytes);

    $tamperedHash = (string) hash_file('sha256', $tampered);
    expect($tamperedHash)->not->toBe($published);

    $this->post(route('verify.check_file', ['code' => $code]), ['sha256' => $tamperedHash])
        ->assertRedirect();

    $checkedAgain = $this->get(route('verify.show', ['code' => $code]))->viewData('page')['props'];
    expect($checkedAgain['file_check']['matches'] ?? null)->toBe('none');

    // O arquivo adulterado também deixa de validar como assinado.
    $validation = app(PdfToolClient::class)->validate($tampered);
    expect($validation->allIntact)->toBeFalse();

    Storage::disk('documents');
});
