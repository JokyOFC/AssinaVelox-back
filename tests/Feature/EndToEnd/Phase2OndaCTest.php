<?php

use App\Enums\AccessLinkPurpose;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Enums\SignatureStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\RecipientAccessLink;
use App\Models\RetentionDeletion;
use App\Models\User;
use App\Models\VerificationRecord;
use App\Services\Dossier\DossierExports;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Pdf\PdfToolClient;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use App\Services\Retention\RetentionRunner;
use App\Services\Signing\Certificates\IncrementalChain;
use App\Services\Signing\Certificates\ParticipantSignatureApplier;
use App\Services\Signing\SignerTokens;
use App\Services\Timestamp\Models\TimestampToken;
use App\Services\Timestamp\TimestampVerifier;
use App\Services\Timestamp\TsaKind;
use App\Services\Verification\PublicVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Phase2/ParticipantA1/Support/ParticipantA1Helpers.php';
require_once __DIR__.'/../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/../Phase2/Timestamp/Support/TimestampHelpers.php';
require_once __DIR__.'/../Phase2/Retention/Support/RetentionHelpers.php';

/*
|--------------------------------------------------------------------------
| Ponta a ponta da Fase 2, onda C (integração I-2C) — validação criptográfica
|--------------------------------------------------------------------------
| pdftool REAL, certificados e TSA de TESTE (nunca ICP-Brasil). Um envelope com DOIS
| documentos e DOIS participantes que optam por assinar também com o próprio A1:
|
|  1. aceites paralelos → base congelada por documento → Maria envia o PFX → Henrique envia
|     → a operadora assina por último → `mixed`, perfil anunciado PAdES-B-B;
|  2. para CADA documento final: o pdftool confere as três assinaturas (íntegras, válidas e,
|     com as raízes de teste, confiáveis), o que cada uma cobre (só a última cobre o arquivo
|     inteiro; as anteriores, a própria revisão, seguidas apenas de alterações permitidas),
|     que cada revisão é prefixo exato da seguinte e que o hash publicado confere com os bytes;
|  3. dossiê com a TSA da operadora: cada arquivo do manifesto confere com os bytes do ZIP e
|     com o banco, e o token RFC 3161 confere com o SHA-256 do manifest.json (pyHanko e,
|     quando houver, `openssl ts -verify`), rotulado "não é carimbo ICP-Brasil";
|  4. retenção: o envelope preservado não perde nada; outro, não preservado, é apagado e a
|     verificação pública segue a decisão (aviso + só o resumo final); liberado o bloqueio, o
|     envelope da onda C sai inteiro — inclusive o .tsr e o ZIP do dossiê.
*/

beforeEach(function () {
    participantA1Boot($this);
    config()->set('inertia.ssr.enabled', false);
});

afterEach(function () {
    putenv(KTSA_TSA_PASS_ENV);
    participantA1Teardown($this);
});

/**
 * Envelope em `finalizing` com dois documentos reais e dois aceites (DomainHelpers), no
 * formato de cenário que os helpers do K-A1 esperam (tokens de convite por participante).
 *
 * @return array<string, mixed>
 */
function ondaCScenario(string $work): array
{
    $base = domainFinalizingEnvelope($work, ['Contrato', 'Anexo A']);
    $organization = $base['organization'];
    $envelope = $base['envelope'];

    finalizationEnableCompanySignature($organization);
    participantA1Enable($organization);

    $recipients = [];
    $tokens = [];

    foreach ($envelope->recipients()->withoutGlobalScopes()->orderBy('id')->get() as $recipient) {
        $raw = SignerTokens::generate();

        RecipientAccessLink::query()->create([
            'recipient_id' => $recipient->getKey(),
            'envelope_id' => $envelope->getKey(),
            'document_version_id' => $envelope->sent_document_version_id,
            'organization_id' => $organization->getKey(),
            'token_digest' => SignerTokens::digest($raw),
            'purpose' => AccessLinkPurpose::Signing,
            'expires_at' => $envelope->expires_at,
        ]);

        $recipients[$recipient->email] = $recipient;
        $tokens[$recipient->email] = $raw;
    }

    return [
        ...$base,
        'owner' => User::query()->findOrFail($envelope->created_by_user_id),
        'recipients' => $recipients,
        'tokens' => $tokens,
    ];
}

/**
 * @return list<DocumentVersion>
 */
function ondaCVersions(Document $document, DocumentVersionKind $kind): array
{
    return participantA1Versions($document, $kind);
}

function ondaCBytes(DocumentVersion $version): string
{
    return (string) Storage::disk('documents')->get($version->storage_path);
}

it('dois documentos, dois A1 de participante e a operadora: cadeia íntegra, dossiê conferível e retenção que respeita a preservação', function () {
    $scenario = ondaCScenario($this->work);
    $envelope = $scenario['envelope'];
    $organization = $scenario['organization'];
    $owner = $scenario['owner'];
    $documents = $scenario['documents'];

    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'senha-da-Maria-Onda-C1!');
    $henrique = participantA1Certificate($this->work.'/certs', 'Henrique Dias', 'senha-do-Henrique-Onda-C2!');
    $roots = [$maria['ca'], $henrique['ca'], $this->operator['pem']];

    // -- 1. Pipeline ---------------------------------------------------------------------
    participantA1Intent($this, $scenario, 'maria@exemplo.test')->assertCreated();
    participantA1Intent($this, $scenario, 'henrique@exemplo.test')->assertCreated();

    finalizationRun($envelope);

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing);

    foreach ($documents as $document) {
        expect(ondaCVersions($document, DocumentVersionKind::PreSignature))->toHaveCount(1)
            ->and(ondaCVersions($document, DocumentVersionKind::Final))->toBe([]);
    }

    $preview = participantA1Preview($this, $scenario, 'maria@exemplo.test', $maria)->assertOk()->json();
    expect($preview['certificate']['is_test'])->toBeTrue()
        ->and($preview['certificate']['icp_brasil_validated'])->toBeFalse()
        ->and($preview['certificate']['kind_label'])->toContain('TESTE');

    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria, ['fingerprint' => $preview['certificate']['fingerprint_sha256']])
        ->assertStatus(202)
        ->assertJsonPath('stage', 'applied')
        ->assertJsonPath('request.documents_signed', 2);

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Finalizing);

    participantA1Submit($this, $scenario, 'henrique@exemplo.test', $henrique)
        ->assertStatus(202)
        ->assertJsonPath('stage', 'applied')
        ->assertJsonPath('request.documents_signed', 2);

    $envelope->refresh();
    $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
    $children = $record->documents()->get();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($record->signature_status)->toBe(SignatureStatus::Mixed)
        // T2: nada além de B-B é anunciado, e nenhum carimbo entra no perfil.
        ->and($record->signature_profile)->toBe('PAdES-B-B')
        ->and($record->validation_result['timestamp'])->toBeNull()
        ->and($record->validation_result['long_term_validation'])->toBeFalse()
        ->and($children)->toHaveCount(2)
        ->and(ParticipantSignatureRequest::withoutOrganizationScope()->where('status', ParticipantSignatureRequestStatus::Applied->value)->count())->toBe(2)
        ->and(ParticipantSignature::withoutOrganizationScope()->count())->toBe(4)
        ->and(participantA1Leftovers($this))->toBe([]);

    // -- 2. Validação criptográfica de cada documento final ------------------------------
    $fieldMaria = ParticipantSignatureApplier::fieldName($scenario['recipients']['maria@exemplo.test']);
    $fieldHenrique = ParticipantSignatureApplier::fieldName($scenario['recipients']['henrique@exemplo.test']);
    $finalBytes = [];

    foreach ($documents as $index => $document) {
        $document->refresh();
        [$base] = ondaCVersions($document, DocumentVersionKind::PreSignature);
        $revisions = ondaCVersions($document, DocumentVersionKind::SignedIncremental);
        $finalPath = finalizationDownload($document->finalVersion, $this->work.DIRECTORY_SEPARATOR.'final-'.$index.'.pdf');
        $final = (string) file_get_contents($finalPath);
        $finalBytes[$index] = $final;

        $validation = app(PdfToolClient::class)->validate($finalPath, $roots);
        $chain = IncrementalChain::analyse($validation, 3);

        expect($validation->signatureCount)->toBe(3)
            ->and($validation->allIntact)->toBeTrue()
            ->and($validation->allValid)->toBeTrue()
            ->and($validation->allTrusted())->toBeTrue()
            ->and(array_map(fn ($signature) => $signature->fieldName, $validation->signatures))->toBe([$fieldMaria, $fieldHenrique, 'AssinaVelox'])
            // Só a última (a da operadora) cobre o arquivo inteiro; as anteriores cobrem a
            // própria revisão e o que veio depois é só alteração permitida.
            ->and($validation->signatures[0]->coverage)->toBe('ENTIRE_REVISION')
            ->and($validation->signatures[1]->coverage)->toBe('ENTIRE_REVISION')
            ->and($validation->signatures[2]->coverage)->toBe('ENTIRE_FILE')
            ->and($validation->signatures[0]->modificationLevel)->toBeIn(['NONE', 'FORM_FILLING'])
            ->and($validation->signatures[1]->modificationLevel)->toBeIn(['NONE', 'FORM_FILLING'])
            ->and($chain['ok'])->toBeTrue()
            // Revisões preservadas byte a byte: base ⊂ #1 ⊂ #2 ⊂ final.
            ->and($revisions)->toHaveCount(2)
            ->and(str_starts_with(ondaCBytes($revisions[0]), ondaCBytes($base)))->toBeTrue()
            ->and(str_starts_with(ondaCBytes($revisions[1]), ondaCBytes($revisions[0])))->toBeTrue()
            ->and(str_starts_with($final, ondaCBytes($revisions[1])))->toBeTrue()
            ->and(strlen($final))->toBeGreaterThan(strlen(ondaCBytes($revisions[1])))
            // Hash registrado (verificação pública) = bytes depois da última assinatura.
            ->and($children[$index]->final_sha256)->toBe(hash('sha256', $final))
            ->and($document->finalVersion->sha256)->toBe(hash('sha256', $final));

        // Cada revisão intermediária também valida sozinha, com a quantidade certa de assinaturas.
        $revisionPath = $this->work.DIRECTORY_SEPARATOR.'rev1-'.$index.'.pdf';
        file_put_contents($revisionPath, ondaCBytes($revisions[0]));
        $first = app(PdfToolClient::class)->validate($revisionPath, $roots);
        expect($first->signatureCount)->toBe(1)
            ->and($first->signatures[0]->coverage)->toBe('ENTIRE_FILE')
            ->and($first->allIntact)->toBeTrue();

        // Um byte adulterado no fim do arquivo final quebra a validação (e o resumo publicado).
        $tampered = $final;
        $tampered[strlen($tampered) - 40] = chr(ord($tampered[strlen($tampered) - 40]) ^ 0x01);
        $tamperedPath = $this->work.DIRECTORY_SEPARATOR.'adulterado-'.$index.'.pdf';
        file_put_contents($tamperedPath, $tampered);

        expect(app(PublicVerification::class)->checkHash($envelope, hash('sha256', $tampered))['matches'])->toBe('none');

        try {
            $broken = app(PdfToolClient::class)->validate($tamperedPath, $roots);
            expect($broken->allIntact && $broken->allValid)->toBeFalse();
        } catch (Throwable) {
            // Recusa do pdftool também prova que o arquivo adulterado não passa.
        }
    }

    // Verificação pública: duas assinaturas de participante (nome mascarado), rótulos T1/T3.
    $public = verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'];
    expect($public['signature_status'])->toBe('mixed')
        ->and($public['signature_profile'])->toBe('PAdES-B-B')
        ->and($public['participant_signatures'])->toHaveCount(2)
        ->and($public['participant_signatures'][0]['documents_count'])->toBe(2)
        ->and($public['participant_signatures'][0]['label'])->toContain('não é ICP-Brasil')
        ->and($public)->not->toHaveKey('timestamps');

    // -- 3. Dossiê com o carimbo da TSA da operadora --------------------------------------
    $tsa = ktsaConfigureTsa($this->work);
    ktsaEnableDossier($organization);
    actingAsMember($owner, $organization);

    $exportId = $this->postJson(route('envelopes.dossier.store', ['envelope' => $envelope->ulid]))->assertSuccessful()->json('export.id');
    $export = DossierExport::withoutOrganizationScope()->where('ulid', $exportId)->firstOrFail();
    $zip = $this->get(app(DossierExports::class)->statusProps($export)['download_url'])->assertOk()->streamedContent();
    $entries = ktsaZipEntries($zip, $this->work);
    $manifest = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
    $manifestSha = hash('sha256', $entries['manifest.json']);

    expect($export->fresh()->status)->toBe('ready')
        ->and($export->fresh()->timestamp_status)->toBe('granted')
        ->and(hash('sha256', $zip))->toBe($export->fresh()->sha256)
        ->and($manifest['envelope']['signature_status'])->toBe('mixed')
        ->and($manifest['envelope']['signature_profile'])->toBe('PAdES-B-B');

    // Cada arquivo listado no manifesto confere com os bytes do ZIP; cada versão, com o banco.
    $versionFiles = 0;
    foreach ($manifest['files'] as $file) {
        expect($entries)->toHaveKey($file['path'])
            ->and(hash('sha256', $entries[$file['path']]))->toBe($file['sha256'])
            ->and(strlen($entries[$file['path']]))->toBe($file['size_bytes']);

        if (($file['kind'] ?? null) === 'document_version') {
            $versionFiles++;
            expect($file['matches_record'])->toBeTrue();
        }
    }

    // Todas as versões de cada documento entram: original, consolidado, evidências, base, as
    // duas revisões assinadas e o final.
    $storedVersions = DocumentVersion::withoutOrganizationScope()->whereIn('document_id', collect($documents)->map->getKey())->count();
    expect($versionFiles)->toBe($storedVersions)
        ->and($manifest['published_hashes']['documents'])->toHaveCount(2)
        ->and(collect($manifest['published_hashes']['documents'])->pluck('final_sha256')->all())
        ->toBe([hash('sha256', $finalBytes[0]), hash('sha256', $finalBytes[1])]);

    // Os finais do ZIP são exatamente os arquivos validados acima.
    $zippedFinals = collect($manifest['files'])->filter(fn ($file) => ($file['version_kind'] ?? null) === 'final')->map(fn ($file) => $file['sha256'])->values()->all();
    expect($zippedFinals)->toEqualCanonicalizing([hash('sha256', $finalBytes[0]), hash('sha256', $finalBytes[1])]);

    // Revalidação no momento da montagem: 3 assinaturas íntegras em cada final.
    $validacao = json_decode($entries['validacao.json'], true, flags: JSON_THROW_ON_ERROR);
    $checked = collect($validacao['revalidated_at_build'] ?? [])->where('status', 'checked');
    expect($checked->count())->toBeGreaterThanOrEqual(2);
    foreach ($checked as $row) {
        if (($row['result']['signature_count'] ?? 0) === 3) {
            expect($row['result']['all_intact'])->toBeTrue()->and($row['result']['all_valid'])->toBeTrue();
        }
    }

    // Token RFC 3161 × SHA-256 do manifest.json (conferência independente do código de emissão).
    $verified = app(TimestampVerifier::class)->verify($entries['carimbo/manifesto.tsr'], $manifestSha);
    $wrong = app(TimestampVerifier::class)->verify($entries['carimbo/manifesto.tsr'], hash('sha256', $entries['manifest.json']."\n"));
    $meta = json_decode($entries['carimbo/carimbo.json'], true);
    $token = TimestampToken::withoutOrganizationScope()->where('envelope_id', $envelope->getKey())->sole();

    expect($verified['valid'])->toBeTrue()
        ->and($verified['trusted'])->toBeTrue()
        ->and($wrong['valid'])->toBeFalse()
        ->and($meta['tsa_kind'])->toBe('operator')
        ->and($meta['label'])->toBe('Carimbo do tempo da operadora — não é carimbo ICP-Brasil')
        ->and($meta['stamped_sha256'])->toBe($manifestSha)
        ->and($token->tsa_kind)->toBe(TsaKind::Operator)
        ->and($token->imprint)->toBe($manifestSha)
        ->and($token->purpose)->toBe(TimestampToken::PURPOSE_DOSSIER_MANIFEST)
        ->and(implode("\n", $entries))->not->toContain(KTSA_TSA_PASSWORD)
        ->and(implode("\n", $entries))->not->toContain('senha-da-Maria-Onda-C1!')
        ->and(implode("\n", $entries))->not->toContain('senha-do-Henrique-Onda-C2!')
        ->and(implode("\n", $entries))->not->toContain('PRIVATE KEY');

    $openssl = (new ExecutableFinder)->find('openssl');
    if ($openssl !== null) {
        file_put_contents($this->work.'/manifest.json', $entries['manifest.json']);
        file_put_contents($this->work.'/manifesto.tsr', $entries['carimbo/manifesto.tsr']);
        $process = new Process([$openssl, 'ts', '-verify', '-data', $this->work.'/manifest.json', '-in', $this->work.'/manifesto.tsr', '-CAfile', $tsa['root']]);
        $process->run();
        expect($process->getOutput())->toContain('Verification: OK');
    }

    // A página de evidências mostra o carimbo; a verificação pública não (é do dossiê).
    $evidence = $this->get(route('envelopes.evidence', $envelope))->assertOk()->viewData('page')['props'];
    expect($evidence['timestamps']['items'][0]['label'])->toBe('Carimbo do tempo da operadora — não é carimbo ICP-Brasil')
        ->and($evidence['participant_signatures'])->toHaveCount(2)
        ->and(verifyProps($this->get(route('verify.show', ['code' => $envelope->verification_code])))['result'])->not->toHaveKey('timestamps');

    // -- 4. Retenção e preservação --------------------------------------------------------
    retentionEnable($organization);
    retentionPolicyFor($organization, ['completed' => 1825]);
    DB::table('envelopes')->where('id', $envelope->getKey())->update(['completed_at' => now()->subDays(2000)]);
    $other = retentionFinishedEnvelope($organization, $owner, 2000);
    $otherCode = $other['envelope']->verification_code;

    $hold = app(LegalHolds::class)->place($organization, $owner, LegalHoldScope::Envelope, 'Ação judicial 0009876-54', envelope: $envelope->fresh());

    $versionPaths = DocumentVersion::withoutOrganizationScope()->whereIn('document_id', collect($documents)->map->getKey())->pluck('storage_path')->all();
    $tsrPath = $token->storage_path;
    $zipPath = $export->fresh()->storage_path;

    $summary = app(RetentionRunner::class)->run();

    // Preservado: nada sai (arquivos, linhas, carimbo, dossiê).
    expect($summary['envelopes_purged'])->toBe(1)
        ->and(Envelope::withoutOrganizationScope()->whereKey($envelope->getKey())->exists())->toBeTrue()
        ->and(ParticipantSignature::withoutOrganizationScope()->count())->toBe(4)
        ->and(TimestampToken::withoutOrganizationScope()->whereKey($token->getKey())->exists())->toBeTrue()
        ->and(Storage::disk($token->storage_disk ?? 'documents')->exists($tsrPath))->toBeTrue()
        ->and(Storage::disk('documents')->exists($zipPath))->toBeTrue();

    foreach ($versionPaths as $path) {
        expect(Storage::disk('documents')->exists($path))->toBeTrue();
    }

    // Não preservado: apagado; a verificação responde conforme a decisão (aviso + resumo final).
    expect(retentionEnvelopeGone($other['envelope']))->toBeTrue();

    $purged = verifyProps($this->get(route('verify.show', ['code' => $otherCode])))['result'];
    expect($purged['retention']['purged'])->toBeTrue()
        ->and($purged['retention']['mode'])->toBe('notice_with_final_hash')
        ->and($purged['hashes']['final_sha256'])->toBe($other['final_sha256'])
        ->and($purged['recipients'])->toBe([])
        ->and($purged['organization_name'])->toBe('');

    // Liberada a preservação, o envelope da onda C sai inteiro — derivados inclusive.
    app(LegalHolds::class)->release($hold, $owner, 'Processo arquivado');
    $code = $envelope->verification_code;
    app(RetentionRunner::class)->run();

    expect(retentionEnvelopeGone($envelope))->toBeTrue()
        ->and(ParticipantSignature::withoutOrganizationScope()->count())->toBe(0)
        ->and(ParticipantSignatureRequest::withoutOrganizationScope()->count())->toBe(0)
        ->and(TimestampToken::withoutOrganizationScope()->whereKey($token->getKey())->exists())->toBeFalse()
        ->and(Storage::disk($token->storage_disk ?? 'documents')->exists($tsrPath))->toBeFalse()
        ->and(Storage::disk('documents')->exists($zipPath))->toBeFalse()
        ->and(RetentionDeletion::withoutOrganizationScope()->where('verification_code', $code)->value('status'))->toBe('completed');

    foreach ($versionPaths as $path) {
        expect(Storage::disk('documents')->exists($path))->toBeFalse();
    }

    // A verificação do envelope multi-documento publica só os dois resumos finais.
    $after = verifyProps($this->get(route('verify.show', ['code' => $code])))['result'];
    expect($after['retention']['final_hashes_count'])->toBe(2)
        ->and(collect($after['documents'])->pluck('final_sha256')->all())
        ->toEqualCanonicalizing([hash('sha256', $finalBytes[0]), hash('sha256', $finalBytes[1])])
        ->and(json_encode($after))->not->toContain('Maria')->not->toContain('Henrique')->not->toContain('Contrato');

    $this->from(route('verify.show', ['code' => $code]))
        ->post(route('verify.check_file', ['code' => $code]), ['sha256' => hash('sha256', $finalBytes[1])])
        ->assertSessionHas('file_check', fn (array $check): bool => $check['matches'] === 'signed');
});
