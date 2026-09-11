<?php

use App\Models\VerificationRecord;
use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Signing\Certificates\ParticipantCertificateTool;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Phase2/Dossier/Support/DossierHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C — custódia e criptografia
|--------------------------------------------------------------------------
| O CN de um e-CPF segue a convenção `NOME DO TITULAR:CPF`. Toda a onda C mascara esse CPF
| (`CertificateInspection::maskCpfIn`) no banco, na trilha e no `validation_result` publicado
| (`ParticipantSignatureStage::validationPayload`), e o dossiê OMITE chaves `cpf`
| (`DossierRedaction`). Mas `DossierBuilder::validationJson()` grava em `validacao.json` o
| `ValidationResult::summary()` CRU da revalidação (linhas 286-287), com `signer_subject`
| completo: o CPF do participante sai por extenso, em texto, no ZIP.
*/

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
    ['organization' => $this->organization, 'owner' => $this->owner] = ktsaDossierSetup($this->work);
    $scenario = ktsaCompletedEnvelope($this->organization, $this->owner, $this->work);
    $this->envelope = $scenario['envelope'];
    $this->final = $scenario['versions']['final'];
});

afterEach(function () {
    ktsaCleanup($this->work ?? null);
});

it('o validacao.json do dossiê não traz o CPF completo do certificado do participante', function () {
    $cpf = '52998224725';
    $tool = app(ParticipantCertificateTool::class);
    $pfx = $this->work.DIRECTORY_SEPARATOR.'maria.pfx';
    $tool->generateTestCertificate($pfx, 'senha-da-Maria-Dossie-2!', 'Maria Alves Souza', $cpf);

    // Arquivo final assinado com o A1 (de TESTE) da participante.
    $input = PdfFixtures::onePagePdf($this->work.DIRECTORY_SEPARATOR.'base.pdf', 'Contrato');
    $signed = $this->work.DIRECTORY_SEPARATOR.'final-assinado.pdf';
    $tool->sign($input, $signed, $pfx, 'senha-da-Maria-Dossie-2!', 'AV_Participante_Maria');
    $bytes = (string) file_get_contents($signed);

    Storage::disk('documents')->put($this->final->storage_path, $bytes);
    $this->final->forceFill(['sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'has_signatures' => true])->save();
    VerificationRecord::query()->where('envelope_id', $this->envelope->getKey())->update(['final_sha256' => hash('sha256', $bytes)]);

    actingAsMember($this->owner, $this->organization);
    $id = $this->postJson(route('envelopes.dossier.store', ['envelope' => $this->envelope->ulid]))->assertSuccessful()->json('export.id');
    $export = DossierExport::withoutOrganizationScope()->where('ulid', $id)->firstOrFail();
    $entries = ktsaZipEntries($this->get(app(DossierExports::class)->statusProps($export)['download_url'])->assertOk()->streamedContent(), $this->work);

    $validation = json_decode($entries['validacao.json'], true);

    // A revalidação rodou de verdade sobre o arquivo assinado…
    expect(collect($validation['revalidated_at_build'])->pluck('status')->all())->toContain('checked')
        // …e não pode expor o CPF (o resto do dossiê só o traz mascarado ou omitido).
        ->and($entries['validacao.json'])->not->toContain($cpf)
        ->and($entries['manifest.json'])->not->toContain($cpf);
});
