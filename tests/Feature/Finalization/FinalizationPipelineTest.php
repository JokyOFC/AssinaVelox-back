<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\SignatureStatus;
use App\Events\EnvelopeReadyForFinalization;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\VerificationRecord;
use App\Notifications\Envelopes\EnvelopeCompletedNotification;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/FinalizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Pipeline de finalização — sem certificado configurado
|--------------------------------------------------------------------------
| Roda contra o pdftool REAL. O que interessa aqui não é "o job não explodiu": é que os
| valores autorizados foram desenhados nas coordenadas certas, que a página de evidências
| está no fim, que o hash final registrado é o do arquivo em disco, e que o envelope
| conclui dizendo exatamente o que é — aceite eletrônico com evidências, sem assinatura
| criptográfica.
*/

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    Notification::fake();

    $this->work = PdfFixtures::workspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    config()->set('pdftool.company_certificate.enabled', false);
    config()->set('pdftool.company_certificate.pfx_path', null);
    config()->set('app.url', 'https://assinavelox.test');
    finalizationDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');
});

afterEach(function () {
    PdfFixtures::cleanup($this->work ?? null);
});

it('consolida, gera evidências e conclui sem assinatura quando não há certificado', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->completed_at)->not->toBeNull()
        ->and($envelope->final_document_version_id)->not->toBeNull();

    $versions = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->get()
        ->keyBy(fn (DocumentVersion $version): string => $version->kind->value);

    expect($versions)->toHaveKeys(['original', 'consolidated', 'evidence', 'final']);

    $final = $versions['final'];
    expect((int) $envelope->final_document_version_id)->toBe((int) $final->getKey());

    // O arquivo final existe, abre e tem as páginas do consolidado + as das evidências.
    $path = finalizationDownload($final, $this->work.'/final.pdf');
    $inspection = app(PdfToolClient::class)->inspect($path);

    expect($inspection->openable)->toBeTrue()
        ->and($inspection->encrypted)->toBeFalse()
        ->and($inspection->hasSignatures)->toBeFalse()
        ->and($inspection->pageCount)->toBe(2 + (int) $versions['evidence']->page_count);

    // Registro de verificação: os quatro hashes, e o final igual ao arquivo em disco.
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    expect($record->code)->toBe($envelope->verification_code)
        ->and($record->original_sha256)->toBe($versions['original']->sha256)
        ->and($record->sent_sha256)->toBe($versions['original']->sha256)
        ->and($record->consolidated_sha256)->toBe($versions['consolidated']->sha256)
        ->and($record->final_sha256)->toBe(hash_file('sha256', $path))
        ->and($record->signature_status)->toBe(SignatureStatus::None)
        ->and($record->signature_profile)->toBeNull()
        ->and($record->certificate_reference_id)->toBeNull()
        ->and($record->validation_result['signed'])->toBeFalse()
        ->and($record->validation_result['reason'])->toBe('signer_not_configured')
        ->and($record->validation_result['timestamp'])->toBeNull()
        ->and($record->validation_result['long_term_validation'])->toBeFalse()
        ->and((int) $record->final_document_version_id)->toBe((int) $final->getKey());
});

it('desenha cada valor autorizado dentro do retângulo do seu campo', function () {
    $scenario = finalizationEnvelope($this->work);

    finalizationRun($scenario['envelope']);

    $final = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->where('kind', DocumentVersionKind::Final->value)
        ->firstOrFail();

    $pages = finalizationPdfPages(finalizationDownload($final, $this->work.'/posicoes.pdf'));

    expect(finalizationTextIsInsideField($pages, $scenario['fields']['name'], 'Maria Alves Souza'))->toBeTrue()
        ->and(finalizationTextIsInsideField($pages, $scenario['fields']['date'], '09/09/2026'))->toBeTrue()
        ->and(finalizationTextIsInsideField($pages, $scenario['fields']['text'], 'reajuste'))->toBeTrue();

    // Texto composto fica em pé em relação à exibição da página (rotação 0 => (1, 0)).
    $run = collect($pages[0]['runs'])->first(fn (array $r): bool => str_contains((string) $r['text'], 'Maria Alves Souza'));
    expect($run)->not->toBeNull()
        ->and(round((float) $run['dx'], 6))->toBe(1.0)
        ->and(round((float) $run['dy'], 6))->toBe(0.0);
});

it('carimba o rodapé de verificação em todas as páginas do documento e nunca o hash final', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);

    $envelope->refresh();

    $consolidated = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->where('kind', DocumentVersionKind::Consolidated->value)
        ->firstOrFail();

    $pages = finalizationPdfPages(finalizationDownload($consolidated, $this->work.'/consolidado.pdf'));
    $code = (string) $envelope->formatted_verification_code;

    expect($pages)->toHaveCount(2);

    foreach ($pages as $page) {
        expect($page['text'])->toContain('assinavelox.test/verificar')
            ->and($page['text'])->toContain($code);
    }

    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $finalPages = finalizationPdfPages(finalizationDownload($envelope->finalVersion, $this->work.'/final-hash.pdf'));
    $allText = implode("\n", array_column($finalPages, 'text'));

    // O hash final é dos bytes do arquivo pronto: ele não pode estar dentro do arquivo.
    expect($allText)->not->toContain($record->final_sha256)
        ->and($allText)->toContain($record->consolidated_sha256)
        ->and($allText)->toContain($record->sent_sha256);
});

it('coloca a página de evidências no fim, com o código e sem o hash final', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);
    $envelope->refresh();

    $evidence = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->where('kind', DocumentVersionKind::Evidence->value)
        ->firstOrFail();

    $finalPages = finalizationPdfPages(finalizationDownload($envelope->finalVersion, $this->work.'/final-evid.pdf'));
    $evidencePages = array_slice($finalPages, 2);

    expect($evidencePages)->toHaveCount((int) $evidence->page_count);

    // O DOMPDF quebra linhas onde couber; para asserções sobre frases o espaço em branco é
    // normalizado (o que se verifica é o texto, não a paginação).
    $evidenceText = (string) preg_replace('/\s+/u', ' ', implode(' ', array_column($evidencePages, 'text')));

    expect($evidenceText)->toContain('Página de evidências')
        ->and($evidenceText)->toContain((string) $envelope->formatted_verification_code)
        ->and($evidenceText)->toContain('Maria Alves Souza')
        ->and($evidenceText)->toContain('Este arquivo não possui assinatura criptográfica')
        ->and($evidenceText)->toContain('aceite eletrônico com evidências')
        ->and($evidenceText)->toContain('Nenhum certificado digital foi utilizado')
        ->and($evidenceText)->toContain('não é um certificado digital')
        // Linha do tempo com os eventos relevantes da trilha.
        ->and($evidenceText)->toContain('Documento enviado')
        ->and($evidenceText)->toContain('Aceite registrado')
        // Acentuação preservada (fonte com cobertura Unicode no DOMPDF).
        ->and($evidenceText)->toContain('Participantes')
        ->and($evidenceText)->toContain('criptográficos');

    // As duas primeiras páginas são o documento; a de evidências vem depois.
    expect($finalPages[0]['text'])->toContain('Contrato de teste')
        ->and($finalPages[0]['text'])->not->toContain('Página de evidências');
});

it('publica um hash final que muda se um único byte do arquivo mudar', function () {
    $scenario = finalizationEnvelope($this->work);

    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();

    $path = finalizationDownload($envelope->finalVersion, $this->work.'/integro.pdf');
    $tampered = $this->work.'/adulterado.pdf';

    finalizationProbe(['tamper', '--in', $path, '--out', $tampered]);

    expect(hash_file('sha256', $path))->toBe($record->final_sha256)
        ->and(hash_file('sha256', $tampered))->not->toBe($record->final_sha256)
        ->and(filesize($tampered))->toBe(filesize($path));
});

it('não desenha nada de quem não tem aceite gravado', function () {
    $scenario = finalizationEnvelope($this->work, [
        finalizationDefaultRecipient(),
        [
            'name' => 'João Pereira',
            'email' => 'joao@exemplo.test',
            'accepted' => false,
            'fields' => [
                ['key' => 'joao_name', 'type' => FieldType::Name, 'page' => 1, 'x' => 0.10, 'y' => 0.80, 'width' => 0.40, 'height' => 0.04],
            ],
        ],
    ]);

    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();
    $pages = finalizationPdfPages(finalizationDownload($envelope->finalVersion, $this->work.'/sem-aceite.pdf'));

    expect($pages[0]['text'])->toContain('Maria Alves Souza')
        ->and(finalizationTextIsInsideField($pages, $scenario['fields']['joao_name'], 'João Pereira'))->toBeFalse();
});

it('respeita a rotação da página na consolidação', function () {
    $flat = PdfFixtures::twoPagePdf($this->work.DIRECTORY_SEPARATOR.'plano.pdf');
    $rotated = $this->work.DIRECTORY_SEPARATOR.'rotacionado.pdf';
    finalizationProbe(['rotate', '--in', $flat, '--out', $rotated, '--page', '2', '--degrees', '90']);

    $scenario = finalizationEnvelope($this->work, [[
        'name' => 'Maria Alves Souza',
        'email' => 'maria@exemplo.test',
        'accepted' => true,
        'fields' => [
            ['key' => 'girado', 'type' => FieldType::Text, 'page' => 2, 'x' => 0.12, 'y' => 0.25, 'width' => 0.40, 'height' => 0.05],
        ],
    ]], sourcePdf: $rotated);

    // A versão congelada guarda a rotação lida do arquivo, não um palpite.
    expect((int) $scenario['version']->pageMeta(2)['rotation'])->toBe(90);

    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();
    $pages = finalizationPdfPages(finalizationDownload($envelope->finalVersion, $this->work.'/girado.pdf'));

    expect((int) $pages[1]['rotation'])->toBe(90)
        ->and(finalizationTextIsInsideField($pages, $scenario['fields']['girado'], 'reajuste'))->toBeTrue();

    // Em pé em relação à EXIBIÇÃO da página rotacionada em 90°: direção (0, 1).
    $run = collect($pages[1]['runs'])->first(fn (array $r): bool => str_contains((string) $r['text'], 'reajuste'));
    expect($run)->not->toBeNull()
        ->and(round((float) $run['dx'], 6))->toBe(0.0)
        ->and(round((float) $run['dy'], 6))->toBe(1.0);
});

it('desenha o nome digitado quando a assinatura foi digitada e não há imagem', function () {
    $scenario = finalizationEnvelope($this->work, [[
        'name' => 'Ana Clara Nogueira',
        'email' => 'ana@exemplo.test',
        'accepted' => true,
        'typed' => true,
        'fields' => [
            ['key' => 'assinatura', 'type' => FieldType::Signature, 'page' => 1, 'x' => 0.10, 'y' => 0.60, 'width' => 0.35, 'height' => 0.07],
        ],
    ]]);

    finalizationRun($scenario['envelope']);

    $envelope = $scenario['envelope']->refresh();
    $pages = finalizationPdfPages(finalizationDownload($envelope->finalVersion, $this->work.'/digitada.pdf'));

    expect(finalizationTextIsInsideField($pages, $scenario['fields']['assinatura'], 'Ana Clara Nogueira'))->toBeTrue();
});

it('registra a trilha da finalização e notifica a conclusão', function () {
    Notification::fake();

    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    finalizationRun($envelope);

    $types = AuditEvent::query()
        ->where('envelope_id', $envelope->getKey())
        ->pluck('event_type')
        ->map(fn (AuditEventType $type): string => $type->value)
        ->all();

    expect($types)->toContain('envelope.consolidated')
        ->and($types)->toContain('envelope.evidence_generated')
        ->and($types)->toContain('envelope.completed')
        ->and($types)->not->toContain('envelope.signed_company_a1');

    Notification::assertSentTimes(EnvelopeCompletedNotification::class, 2);
});

it('é disparado pelo gancho deixado no último aceite', function () {
    Bus::fake();

    EnvelopeReadyForFinalization::dispatch(42, 7, 'chave', 'correlacao');

    Bus::assertDispatched(FinalizeEnvelope::class, function (FinalizeEnvelope $job): bool {
        return $job->envelopeId === 42
            && $job->organizationId === 7
            && $job->finalizationKey === 'chave'
            && $job->queue === config('assinavelox.queues.finalization');
    });
});

it('não toca em envelope que não está finalizando', function () {
    $scenario = finalizationEnvelope($this->work);
    $envelope = $scenario['envelope'];

    $envelope->forceFill(['status' => EnvelopeStatus::Refused, 'refused_at' => now()])->save();

    finalizationRun($envelope);

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Refused)
        ->and($envelope->final_document_version_id)->toBeNull()
        ->and(VerificationRecord::query()->where('envelope_id', $envelope->getKey())->exists())->toBeFalse()
        ->and(DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $scenario['document']->getKey())
            ->whereIn('kind', ['consolidated', 'evidence', 'final'])
            ->count())->toBe(0);
});

it('deixa o disco temporário limpo depois de finalizar', function () {
    $scenario = finalizationEnvelope($this->work);

    finalizationRun($scenario['envelope']);

    expect(glob($this->work.'/pdftool-tmp/*') ?: [])->toBe([]);

    // E os artefatos ficaram no disco privado `documents`, cada um com bytes próprios.
    $paths = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->pluck('storage_path');

    expect($paths)->toHaveCount(4)
        ->and($paths->unique())->toHaveCount(4);

    foreach ($paths as $path) {
        expect(Storage::disk('documents')->exists($path))->toBeTrue();
    }
});
