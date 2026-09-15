<?php

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Models\AnchorScan;
use App\Models\AuditEvent;
use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\Delegation;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\FieldSuggestion;
use App\Models\PlanConsumption;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningStep;
use App\Models\Template;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Services\BulkGeneration\BulkGenerationStatus;
use App\Services\BulkGeneration\BulkRowOutcome;
use App\Services\BulkGeneration\BulkRowStatus;
use App\Services\Envelopes\Finalization\EvidenceData;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Identity\CaptureKind;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Identity\Models\IdentityVideoRequirement;
use App\Services\Pdf\PdfToolClient;
use App\Services\Templates\CreateEnvelopeFromTemplate;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Documents/Support/helpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Finalization/Support/FinalizationHelpers.php';
require_once __DIR__.'/../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/../Phase2/Templates/Support/TemplateHelpers.php';
require_once __DIR__.'/../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/../Phase3/Bulk/Support/BulkHelpers.php';
require_once __DIR__.'/../Phase3/Anchors/Support/AnchorHelpers.php';
require_once __DIR__.'/../Phase3/Flow/Support/FlowHelpers.php';
require_once __DIR__.'/../Phase3/Video/Support/VideoHelpers.php';
require_once __DIR__.'/../Phase3/I18n/Support/I18nHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta da Fase 3, parte 2 — onda F (integração I-3F)
|--------------------------------------------------------------------------
| Com as sete flags da onda ligadas (interruptor global E plano), pelas rotas HTTP reais:
|
|  modelo PDF com regras de âncora → lote de 5 linhas (uma inválida, recusada na
|  pré-validação: a cota é reservada só para 4) → cada envelope nasce com a SUGESTÃO de campo
|  pendente (nunca envio automático) → num deles o remetente confirma a sugestão e salva o
|  campo pelo editor → define etapas (aprovação → compradora se aprovou → jurídico se recusou),
|  delegação, idioma inglês e vídeo curto para a compradora → envia → a aprovadora aprova, a
|  etapa 2 corre → a compradora delega (a exigência de vídeo força a confirmação de quem
|  enviou) → a delegada, que herda idioma e exigência, grava o vídeo e aceita com a página em
|  inglês → a etapa 3 é pulada com registro → a finalização conclui com o pdftool REAL e o
|  certificado de TESTE da operadora → as evidências mostram etapa pulada, delegação, idioma
|  exibido e o SHA-256 do vídeo (sem o vídeo embutido).
|
| Um segundo caso percorre o mesmo modelo com as sete flags DESLIGADAS: fluxo antigo, rotas
| novas 404, nenhuma linha nas tabelas novas e evidências sem chave nova.
*/

const WAVEF_CERT_PASS_ENV = 'WAVEF_TEST_CERT_PASS';

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->withoutVite();
    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.trust_roots', []);
    config()->set('inertia.ssr.enabled', false);
    fakeDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
    Http::preventStrayRequests();

    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Horizonte Onda F']);
    actingAsMember($this->owner, $this->organization);

    $this->codes = signerCaptureCodes();
    $this->invites = new ArrayObject;
    $this->inviteCounts = new ArrayObject;
    flowCaptureInvites($this->invites, $this->inviteCounts);

    // Idioma com que cada convite saiu (null = PT-BR, o padrão do framework).
    $this->inviteLocales = new ArrayObject;
    $locales = $this->inviteLocales;
    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($locales): void {
        if ($event->notification instanceof RecipientInvitationNotification) {
            $locales[$event->notification->recipient->email] = $event->notification->locale;
        }
    });

    // Certificado A1 de TESTE da operadora (nunca de produção) e o item no plano.
    putenv(WAVEF_CERT_PASS_ENV.'=senha-de-teste-Wf82!');
    $this->certificate = TestCertificate::generate($this->work.DIRECTORY_SEPARATOR.'certs', WAVEF_CERT_PASS_ENV, TestCertificate::SUBJECT, 2);
    TestCertificate::configure($this->certificate);
    TestCertificate::register($this->certificate);
    finalizationEnableCompanySignature($this->organization);
});

afterEach(function () {
    putenv(WAVEF_CERT_PASS_ENV);
    PdfFixtures::cleanup($this->work ?? null);
});

/** Modelo PDF (2 páginas: "Contrato de teste" / "Anexo") com aprovação e dois signatários. */
function waveFTemplate(object $test): Template
{
    return templatePdf($test->organization, $test->owner, $test->work, [
        ['ref' => 'gestora', 'name' => 'Gestora', 'participant_role' => 'approver'],
        ['ref' => 'compradora', 'name' => 'Compradora', 'participant_role' => 'signer'],
        ['ref' => 'juridico', 'name' => 'Jurídico', 'participant_role' => 'signer'],
    ], [
        ['role_ref' => 'compradora', 'type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'w' => 0.3, 'h' => 0.06],
        ['role_ref' => 'juridico', 'type' => 'signature', 'page' => 2, 'x' => 0.55, 'y' => 0.8, 'w' => 0.3, 'h' => 0.06],
    ], 'Contrato de compra');
}

/** Texto corrido de um PDF, com o espaço em branco normalizado. */
function waveFPdfText(string $path): string
{
    $text = '';

    foreach (finalizationPdfPages($path) as $page) {
        foreach ($page['runs'] as $run) {
            $text .= ' '.(string) $run['text'];
        }
    }

    return (string) preg_replace('/\s+/u', ' ', $text);
}

/**
 * Campos atuais do envelope no formato do editor (PUT envelopes.fields.sync).
 *
 * @return list<array<string, mixed>>
 */
function waveFEditorFields(Envelope $envelope): array
{
    return SigningField::withoutOrganizationScope()
        ->where('envelope_id', $envelope->getKey())
        ->with('recipient')
        ->orderBy('id')
        ->get()
        ->map(fn (SigningField $field): array => [
            'id' => $field->ulid,
            'recipient_id' => $field->recipient?->ulid,
            'type' => $field->type->value,
            'page' => $field->page,
            'x' => (float) $field->x,
            'y' => (float) $field->y,
            'w' => (float) $field->width,
            'h' => (float) $field->height,
            'required' => (bool) $field->required,
        ])
        ->all();
}

/** @return array<string, mixed> */
function waveFEvidence(Envelope $envelope): array
{
    $version = DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id);

    return app(EvidenceData::class)->build($envelope, $version, ['original' => 'a', 'sent' => 'b', 'consolidated' => 'c'], SignatureStatus::None);
}

it('lote com âncoras → sugestão confirmada → etapas, delegação confirmada, delegada em inglês com vídeo → conclusão, pdftool e evidências', function () {
    // -- 0. As sete flags da onda (global E plano), pelos helpers de cada área ------------
    bulkEnable($this->organization, participantRoles: true);
    anchorsEnable($this->organization, ocr: true);
    flowEnableFlags($this->organization);
    identityEnableFlags($this->organization, ['identity_video']);
    i18nEnableFlag($this->organization);
    // OCR ligado, mas sem Tesseract: a disponibilidade é verificada de verdade, nunca presumida.
    config()->set('assinavelox.ocr.driver', 'tesseract');
    config()->set('assinavelox.ocr.tesseract_path', null);
    // Cota do plano: exatamente 4 envelopes.
    bulkQuota($this->organization, 4);

    $shared = $this->get(route('dashboard'))->viewData('page')['props']['features'];
    foreach (['bulk_generation', 'field_anchors', 'ocr', 'conditional_steps', 'delegation', 'identity_video', 'multilingual'] as $flag) {
        expect($shared[$flag])->toBeTrue("a flag {$flag} deveria estar ligada");
    }

    // -- 1. Modelo com regra de âncora: data abaixo de "Contrato de teste", para a compradora
    $template = waveFTemplate($this);
    $this->putJson(route('anchors.template.update', $template), ['rules' => [[
        'pattern' => 'Contrato de teste',
        'field_type' => 'date',
        'role_position' => 2,
        'placement' => 'below',
        'offset_x_pt' => 0,
        'offset_y_pt' => 4,
        'width_pt' => 100,
        'height_pt' => 18,
        'required' => true,
        'occurrence' => 'first',
    ]]])->assertOk()->assertJsonPath('rules.0.role_name', 'Compradora');

    // -- 2. Lote de 5 linhas: a 3ª é inválida (e-mail e fórmula) -------------------------
    $rows = [['Gestora — nome', 'Gestora — e-mail', 'Compradora — nome', 'Compradora — e-mail', 'Jurídico — nome', 'Jurídico — e-mail', 'Título do documento']];
    $rows[] = ['Paula Aprovadora', 'paula@exemplo.test', 'Ana Compradora', 'ana@exemplo.test', 'Bruno Jurídico', 'bruno@exemplo.test', 'Compra 1'];

    for ($n = 2; $n <= 5; $n++) {
        $rows[] = $n === 3
            ? ["Gestora {$n}", "gestora{$n}@exemplo.test", "Compradora {$n}", 'compradora-sem-arroba', "Jurídico {$n}", "juridico{$n}@exemplo.test", '=HYPERLINK("http://x.test","abrir")']
            : ["Gestora {$n}", "gestora{$n}@exemplo.test", "Compradora {$n}", "compradora{$n}@exemplo.test", "Jurídico {$n}", "juridico{$n}@exemplo.test", "Compra {$n}"];
    }

    $batch = bulkValidate(bulkUpload($template, bulkFile(bulkCsv($this->work.DIRECTORY_SEPARATOR.'lote.csv', $rows))));

    expect($batch->valid_count)->toBe(4)
        ->and($batch->invalid_count)->toBe(1)
        // A pré-validação não cria envelope nem reserva cota.
        ->and(Envelope::query()->count())->toBe(0)
        ->and(PlanConsumption::query()->where('idempotency_key', 'like', "bulk:{$batch->id}:row:%")->count())->toBe(0);

    $invalid = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->where('status', BulkRowStatus::Invalid->value)->sole();
    expect($invalid->row_index)->toBe(4); // linha 4 da planilha (a 1ª é o cabeçalho)

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();
    $batch->refresh();

    $reservations = PlanConsumption::query()->where('idempotency_key', 'like', "bulk:{$batch->id}:row:%")->get();

    expect($batch->status)->toBe(BulkGenerationStatus::Completed)
        ->and($batch->created_count)->toBe(4)
        // Uma unidade por linha VÁLIDA (4) — e cada uma devolvida quando o rascunho nasceu.
        ->and($reservations)->toHaveCount(4)
        ->and($reservations->pluck('idempotency_key')->contains("bulk:{$batch->id}:row:{$invalid->id}"))->toBeFalse()
        ->and(Envelope::query()->count())->toBe(4);

    // Âncoras só SUGEREM: todo envelope gerado espera a revisão do remetente, nenhum foi enviado.
    expect(Envelope::query()->pluck('status')->unique()->all())->toBe([EnvelopeStatus::Draft])
        ->and(BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->where('outcome', BulkRowOutcome::Draft->value)->count())->toBe(4)
        ->and(AnchorScan::query()->where('trigger', 'template')->count())->toBe(4)
        ->and(FieldSuggestion::query()->where('status', 'pending')->count())->toBe(4)
        ->and($this->invites->count())->toBe(0);

    // Relatório: coluna e mensagem, nunca o valor digitado nem a fórmula.
    $report = $this->get(route('bulk_generations.report', $batch))->assertOk()->streamedContent();
    expect($report)->toContain('Compradora — e-mail')
        ->and($report)->not->toContain('compradora-sem-arroba')
        ->and($report)->not->toContain('HYPERLINK');

    // -- 3. Um dos envelopes: a sugestão é confirmada e o campo salvo pelo editor ---------
    $envelope = Envelope::query()->where('title', 'Compra 1')->sole();
    $recipients = Recipient::query()->where('envelope_id', $envelope->id)->get()->keyBy('email');
    [$paula, $ana, $bruno] = [$recipients['paula@exemplo.test'], $recipients['ana@exemplo.test'], $recipients['bruno@exemplo.test']];

    $state = $this->getJson(route('anchors.envelope.index', ['envelope' => $envelope->ulid]))->assertOk()->json();
    // Sem Tesseract, o OCR é honesto: indisponível, nunca simulado fora de local/testing.
    expect($state['ocr']['enabled'])->toBeTrue()
        ->and($state['ocr']['available'])->toBeFalse()
        ->and($state['ocr']['simulated'])->toBeFalse()
        ->and($state['ocr']['message'])->toBe('OCR indisponível neste servidor')
        ->and($state['pending_count'])->toBe(1);

    $suggestion = FieldSuggestion::query()->where('envelope_id', $envelope->id)->sole();
    expect($suggestion->recipient_id)->toBe($ana->id)
        ->and($suggestion->type)->toBe(FieldType::Date);

    $accepted = $this->postJson(route('anchors.suggestions.accept', [$envelope, $suggestion->ulid]))->assertOk()->json('field');

    $this->put(route('envelopes.fields.sync', ['envelope' => $envelope->ulid]), [
        'fields' => [...waveFEditorFields($envelope), $accepted],
    ])->assertSessionHasNoErrors();

    expect(SigningField::query()->where('envelope_id', $envelope->id)->where('type', FieldType::Date->value)->where('recipient_id', $ana->id)->count())->toBe(1)
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);

    // -- 4. Preparo: etapas, delegação, idioma e vídeo ----------------------------------
    flowSaveSteps($this, ['envelope' => $envelope], [
        ['name' => 'Aprovação', 'recipients' => [$paula->ulid]],
        ['name' => 'Assinatura da compradora', 'recipients' => [$ana->ulid], 'condition' => flowDecisionRule($paula->ulid, 'approved')],
        ['name' => 'Revisão jurídica', 'recipients' => [$bruno->ulid], 'condition' => flowDecisionRule($paula->ulid, 'refused')],
    ])->assertOk();

    // Confirmação DESLIGADA na política: a exigência de vídeo a força mesmo assim.
    $this->putJson(route('envelopes.delegation.update', ['envelope' => $envelope->ulid]), [
        'allow' => true,
        'requires_confirmation' => false,
        'personal' => [],
    ])->assertOk();

    $this->putJson(route('envelopes.recipients.locale', ['envelope' => $envelope->ulid, 'recipient' => $ana->ulid]), [
        'locale' => 'en',
        'timezone' => 'America/New_York',
    ])->assertOk();

    $this->putJson(route('envelopes.recipients.identity_video', ['envelope' => $envelope->ulid, 'recipient' => $ana->ulid]), [
        'required' => true,
        'max_seconds' => 10,
    ])->assertOk();

    flowSend($this, ['envelope' => $envelope])->assertSessionHasNoErrors();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and(SigningStep::query()->where('envelope_id', $envelope->id)->orderBy('step_index')->pluck('status')->all())->toBe(['active', 'pending', 'pending'])
        ->and(array_keys($this->invites->getArrayCopy()))->toBe(['paula@exemplo.test'])
        ->and($this->inviteLocales['paula@exemplo.test'])->toBeNull();

    // -- 5. A aprovadora aprova: a etapa 2 corre, convite em inglês para a compradora ----
    flowSign($this, $this->invites['paula@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();

    expect(SigningStep::query()->where('envelope_id', $envelope->id)->orderBy('step_index')->pluck('status')->all())->toBe(['active', 'active', 'pending'])
        ->and(isset($this->invites['ana@exemplo.test']))->toBeTrue()
        ->and(isset($this->invites['bruno@exemplo.test']))->toBeFalse()
        ->and($this->inviteLocales['ana@exemplo.test'])->toBe('en');

    // -- 6. A compradora delega: a exigência de vídeo força a confirmação de quem enviou --
    $anaToken = $this->invites['ana@exemplo.test'];
    $anaProps = domainAuthenticate($this, $anaToken);
    expect($anaProps['i18n']['locale'])->toBe('en');

    $this->getJson(route('sign.delegation.show', ['token' => $anaToken]))
        ->assertOk()
        ->assertJsonPath('can_delegate', true)
        ->assertJsonPath('requires_confirmation', true);

    flowDelegate($this, $anaToken)->assertCreated()->assertJsonPath('status', 'pending');

    $delegation = Delegation::query()->sole();
    $this->post(route('envelopes.delegations.approve', ['envelope' => $envelope->ulid, 'delegation' => $delegation->ulid]))
        ->assertSessionHas('success');

    $carla = Recipient::query()->where('envelope_id', $envelope->id)->where('email', 'carla@exemplo.test')->sole();

    expect($ana->fresh()->status)->toBe(RecipientStatus::Delegated)
        ->and($carla->getAttribute('delegated_from_recipient_id'))->toBe($ana->id)
        ->and($carla->getAttribute('signing_step_index'))->toBe(2)
        // A delegada herda o idioma escolhido para a posição e a exigência de vídeo.
        ->and($carla->getAttribute('locale'))->toBe('en')
        ->and(IdentityVideoRequirement::query()->withoutGlobalScopes()->where('recipient_id', $carla->id)->value('max_seconds'))->toBe(10)
        // Os dois campos da compradora (assinatura e a data da âncora) passaram para ela.
        ->and(SigningField::query()->where('recipient_id', $carla->id)->count())->toBe(2)
        ->and($this->inviteLocales['carla@exemplo.test'])->toBe('en');

    // O link da compradora deixou de abrir.
    $this->get(route('sign.show', ['token' => $anaToken]))->assertNotFound();

    // -- 7. A delegada: página em inglês, vídeo curto e aceite PRÓPRIO -----------------
    $carlaToken = $this->invites['carla@exemplo.test'];
    $carlaProps = domainAuthenticate($this, $carlaToken);

    expect($carlaProps['i18n']['locale'])->toBe('en')
        ->and($carlaProps['i18n']['legal_reviewed'])->toBeFalse()
        ->and($carlaProps['consent']['translation']['locale'])->toBe('en')
        // O texto de referência (o que é gravado) continua em PT-BR.
        ->and($carlaProps['consent']['statement'])->toStartWith('Declaração de aceite eletrônico')
        ->and($carlaProps['identity_video']['complete'])->toBeFalse()
        // I-3F (QA): a dica da caixa de assinatura nasce em PHP e também sai no idioma da página.
        ->and(collect($carlaProps['my_fields'])->firstWhere('type', 'signature')['placeholder'])->toBe('Click to sign here');

    domainPresent($this, $carlaProps);
    domainAccept($this, $carlaToken, $carlaProps)->assertSessionHasErrors('signature');

    $video = videoWebm(6000.0);
    videoPost($this, $carlaToken, $video)->assertCreated()->assertJsonPath('identity_video.complete', true);

    domainAccept($this, $carlaToken, $carlaProps)->assertSessionHasNoErrors();

    // -- 8. A etapa 3 foi pulada com registro; a finalização concluiu -------------------
    $envelope->refresh();
    $bruno->refresh();

    expect(SigningStep::query()->where('envelope_id', $envelope->id)->orderBy('step_index')->pluck('status')->all())->toBe(['active', 'active', 'skipped'])
        ->and($bruno->status)->toBe(RecipientStatus::Canceled)
        ->and($bruno->getAttribute('status_reason'))->toBe('step_skipped')
        ->and($bruno->notification_count)->toBe(0)
        ->and(isset($this->invites['bruno@exemplo.test']))->toBeFalse()
        ->and($envelope->status)->toBe(EnvelopeStatus::Completed);

    $skipped = AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', 'envelope.step_skipped')->sole();
    expect($skipped->payload['rules'][0]['observed'])->toBe('approved')
        ->and($skipped->payload['canceled_recipients'])->toBe([$bruno->ulid]);

    $acceptance = SignatureAcceptance::query()->where('recipient_id', $carla->id)->sole();
    $capture = IdentityCapture::withoutOrganizationScope()->where('recipient_id', $carla->id)->sole();

    expect($acceptance->display_locale)->toBe('en')
        ->and($acceptance->consent_statement)->toStartWith('Declaração de aceite eletrônico')
        ->and(SignatureAcceptance::query()->where('recipient_id', $ana->id)->exists())->toBeFalse()
        ->and($capture->kind)->toBe(CaptureKind::Video)
        ->and($capture->sha256)->toBe(hash('sha256', $video))
        ->and($capture->signature_acceptance_id)->toBe($acceptance->id);

    foreach (['recipient.locale_updated', 'identity_video.requirement_updated', 'signing_steps.updated', 'delegation.requested', 'recipient.delegated', 'identity_video.recorded', 'envelope.step_started'] as $type) {
        expect(AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', $type)->exists())->toBeTrue("evento {$type} ausente");
    }

    // -- 9. pdftool valida o arquivo final ------------------------------------------
    $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final.pdf');
    $validation = app(PdfToolClient::class)->validate($final, [$this->certificate['pem']]);

    expect($validation->signatureCount)->toBe(1)
        ->and($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue()
        ->and($validation->allTrusted())->toBeTrue()
        ->and($validation->signatures[0]->coverage)->toBe('ENTIRE_FILE');

    // -- 10. Evidências: etapa pulada, delegação, idioma exibido e hash do vídeo --------
    $data = waveFEvidence($envelope);
    $participants = collect($data['participants'])->keyBy('email');

    expect($participants['ana@exemplo.test']['status'])->toBe('delegated')
        ->and($participants['ana@exemplo.test']['flow_note'])->toContain('Delegou a Carla Souza')
        ->and($participants['carla@exemplo.test']['flow_note'])->toContain('delegação de Ana Compradora')
        ->and($participants['carla@exemplo.test']['display_locale_label'])->toContain('English')
        ->and($participants['carla@exemplo.test']['identity_video_label'])->toContain($capture->sha256)
        ->and($participants['bruno@exemplo.test']['flow_note'])->toContain('etapa 3')
        ->and(collect($data['flow']['steps'])->pluck('status')->all())->toContain('skipped')
        ->and($data['flow']['delegations'][0]['confirmed_at'])->not->toBeNull();

    $pdfBytes = (string) file_get_contents($final);
    $text = waveFPdfText($final);
    $compact = str_replace(' ', '', $text);

    expect($text)->toContain('Delegações')
        ->and($text)->toContain('Etapas do fluxo')
        ->and($text)->toContain('Idioma da página: English')
        ->and($text)->toContain('não faz parte deste PDF')
        ->and($compact)->toContain($capture->sha256)
        // O vídeo é só CITADO: nenhum arquivo embutido no PDF.
        ->and($pdfBytes)->not->toContain('/EmbeddedFile')
        // T1: o PDF só usa "em nome de" para NEGAR (a delegada assina por si; a operadora não
        // tem certificado emitido em nome dos participantes) — nunca de forma afirmativa.
        ->and($text)->toContain('nunca em nome de outra pessoa')
        ->and(str_replace(['nunca em nome de outra pessoa', 'emitido em nome deles'], '', $text))->not->toContain('em nome de');

    // Os outros três envelopes do lote continuam esperando a revisão (nada automático).
    expect(Envelope::query()->where('id', '!=', $envelope->id)->pluck('status')->unique()->all())->toBe([EnvelopeStatus::Draft]);
})->group('slow');

it('com as sete flags desligadas, o mesmo modelo percorre o fluxo antigo: rotas novas 404, nada gravado, evidências iguais', function () {
    // Só o que já existia antes da onda F: modelos e papéis (Fase 2).
    templatesEnable($this->organization, participantRoles: true);
    domainEnableFlags($this->organization);
    setPlanQuota($this->organization, 5);

    $shared = $this->get(route('dashboard'))->viewData('page')['props']['features'];
    foreach (['bulk_generation', 'field_anchors', 'ocr', 'conditional_steps', 'delegation', 'identity_video', 'multilingual'] as $flag) {
        expect($shared[$flag])->toBeFalse("a flag {$flag} deveria estar desligada");
    }

    $template = waveFTemplate($this);
    $roles = templateRoleIds($template);

    // Rotas novas do remetente: 404.
    $this->get(route('bulk_generations.create', $template))->assertNotFound();
    $this->getJson(route('anchors.template.index', $template))->assertNotFound();

    $envelope = app(CreateEnvelopeFromTemplate::class)->handle($template->fresh(), $this->owner, [
        'title' => 'Compra sem onda F',
        'participants' => [
            $roles['Gestora'] => ['name' => 'Paula Aprovadora', 'email' => 'paula@exemplo.test'],
            $roles['Compradora'] => ['name' => 'Ana Compradora', 'email' => 'ana@exemplo.test'],
            $roles['Jurídico'] => ['name' => 'Bruno Jurídico', 'email' => 'bruno@exemplo.test'],
        ],
    ]);

    $ana = Recipient::query()->where('envelope_id', $envelope->id)->where('email', 'ana@exemplo.test')->sole();

    // Nada de busca de âncoras: o envelope nasce pronto, como antes.
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and(AnchorScan::query()->count())->toBe(0);

    $this->getJson(route('anchors.envelope.index', ['envelope' => $envelope->ulid]))->assertNotFound();
    $this->getJson(route('envelopes.flow.show', ['envelope' => $envelope->ulid]))->assertNotFound();
    $this->putJson(route('envelopes.steps.update', ['envelope' => $envelope->ulid]), ['enabled' => true, 'steps' => []])->assertNotFound();
    $this->putJson(route('envelopes.delegation.update', ['envelope' => $envelope->ulid]), ['allow' => true])->assertNotFound();
    $this->getJson(route('envelopes.recipients.locales', ['envelope' => $envelope->ulid]))->assertNotFound();
    $this->putJson(route('envelopes.recipients.locale', ['envelope' => $envelope->ulid, 'recipient' => $ana->ulid]), ['locale' => 'en'])->assertNotFound();
    $this->putJson(route('envelopes.recipients.identity_video', ['envelope' => $envelope->ulid, 'recipient' => $ana->ulid]), ['required' => true])->assertNotFound();

    flowSend($this, ['envelope' => $envelope])->assertSessionHasNoErrors();

    // Sequencial, como na Fase 2: só a aprovadora é convidada, em PT-BR.
    expect(array_keys($this->invites->getArrayCopy()))->toBe(['paula@exemplo.test'])
        ->and($this->inviteLocales['paula@exemplo.test'])->toBeNull()
        ->and($envelope->fresh()->getAttribute('uses_signing_steps'))->toBeFalse();

    flowSign($this, $this->invites['paula@exemplo.test'], [], withSignature: false)->assertSessionHasNoErrors();

    $anaToken = $this->invites['ana@exemplo.test'];
    $anaProps = domainAuthenticate($this, $anaToken);

    expect($anaProps)->not->toHaveKey('i18n')
        ->and($anaProps)->not->toHaveKey('identity_video')
        ->and($anaProps['consent'])->not->toHaveKey('translation');

    $this->getJson(route('sign.delegation.show', ['token' => $anaToken]))->assertNotFound();
    $this->post(route('sign.locale.update', ['token' => $anaToken]), ['locale' => 'en'])->assertNotFound();

    domainPresent($this, $anaProps);
    domainAccept($this, $anaToken, $anaProps)->assertSessionHasNoErrors();

    flowSign($this, $this->invites['bruno@exemplo.test'])->assertSessionHasNoErrors();

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and(SignatureAcceptance::query()->whereNotNull('display_locale')->exists())->toBeFalse()
        ->and(collect($this->inviteLocales->getArrayCopy())->filter()->all())->toBe([]);

    // Nenhuma linha nas tabelas novas da onda.
    foreach (['bulk_generations', 'bulk_generation_rows', 'field_anchor_rules', 'anchor_scans', 'field_suggestions', 'signing_steps', 'delegations', 'identity_video_requirements'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "a tabela {$table} deveria continuar vazia");
    }

    // Evidências sem chave nova; o arquivo final continua assinado pela operadora (teste).
    $data = waveFEvidence($envelope);

    expect($data)->not->toHaveKey('flow');

    foreach ($data['participants'] as $row) {
        expect($row)->not->toHaveKey('flow_note')
            ->and($row)->not->toHaveKey('display_locale_label')
            ->and($row)->not->toHaveKey('identity_video_label');
    }

    $final = finalizationDownload($envelope->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final-sem-onda-f.pdf');
    $validation = app(PdfToolClient::class)->validate($final, [$this->certificate['pem']]);
    $text = waveFPdfText($final);

    expect($validation->signatureCount)->toBe(1)
        ->and($validation->allIntact)->toBeTrue()
        ->and($validation->allValid)->toBeTrue()
        ->and($text)->not->toContain('Delegações')
        ->and($text)->not->toContain('Etapas do fluxo')
        ->and($text)->not->toContain('Idioma da página');

    expect(BulkGeneration::query()->count())->toBe(0);
})->group('slow');
