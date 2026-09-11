<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial Fase 2 onda C (semântica) — certificado de TESTE com selo verde "válida"
|--------------------------------------------------------------------------
| Verificação pública (visto no navegador, código YUBR-SPZQ-4EJD, assinatura de participante com
| certificado de TESTE): o cartão da assinatura mostra, lado a lado, o selo verde "Íntegra e válida"
| (resources/js/lib/labels.ts:448, tom `success`) e o aviso "Certificado de TESTE — não é ICP-Brasil e
| não tem validade jurídica"; a linha "Integridade" repete "Assinatura íntegra e válida no arquivo
| final." (app/Services/Signing/Certificates/ParticipantSignatureViews.php:147). Para um leigo, "válida"
| em verde contradiz "não tem validade jurídica" no mesmo cartão — e é a primeira coisa que ele lê.
| "Válida" aqui é só o resultado criptográfico; com certificado de teste a página precisa dizer
| isso (ex.: "Íntegra — certificado de teste"), nunca "válida" sem ressalva.
*/

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Services\Signing\Certificates\ParticipantSignatureViews;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('não chama de "válida" a assinatura feita com certificado de TESTE na verificação pública', function () {
    $this->seed(DatabaseSeeder::class);

    /** @var Envelope $envelope */
    $envelope = Envelope::withoutGlobalScopes()
        ->where('status', EnvelopeStatus::Completed)
        ->whereIn('id', VerificationRecord::query()->select('envelope_id'))
        ->orderBy('id')
        ->firstOrFail();

    $acceptance = DB::table('signature_acceptances')->where('envelope_id', $envelope->id)->first();
    $document = DB::table('documents')->where('envelope_id', $envelope->id)->first();
    $versions = DB::table('document_versions')->where('document_id', $document->id)->pluck('id', 'kind');
    $now = now();

    $requestId = DB::table('participant_signature_requests')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'organization_id' => $envelope->organization_id,
        'envelope_id' => $envelope->id,
        'recipient_id' => $acceptance->recipient_id,
        'status' => 'applied',
        'consent_version' => 'teste',
        'consented_at' => $now,
        'subject' => 'CN=Ana Beatriz Rocha TESTE',
        'subject_cn' => 'Ana Beatriz Rocha TESTE',
        'issuer' => 'CN=AC TESTE AssinaVelox',
        'issuer_cn' => 'AC TESTE AssinaVelox',
        'serial_number' => '1A2B',
        'fingerprint_sha256' => str_repeat('ab', 32),
        'not_before' => $now->copy()->subDay(),
        'not_after' => $now->copy()->addYear(),
        'is_test_certificate' => true,
        'certificate_facts' => json_encode(['self_signed' => false, 'declares_icp_brasil_policy' => false]),
        'attempts' => 1,
        'applied_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('participant_signatures')->insert([
        'ulid' => (string) Str::ulid(),
        'organization_id' => $envelope->organization_id,
        'envelope_id' => $envelope->id,
        'participant_signature_request_id' => $requestId,
        'recipient_id' => $acceptance->recipient_id,
        'document_id' => $document->id,
        'signature_acceptance_id' => $acceptance->id,
        'base_document_version_id' => $versions['evidence'],
        'signed_document_version_id' => $versions['final'],
        'revision_index' => 1,
        'field_name' => 'AV-P1',
        'profile' => 'PAdES-B-B',
        'subject' => 'CN=Ana Beatriz Rocha TESTE',
        'issuer' => 'CN=AC TESTE AssinaVelox',
        'serial_number' => '1A2B',
        'fingerprint_sha256' => str_repeat('ab', 32),
        'not_before' => $now->copy()->subDay(),
        'not_after' => $now->copy()->addYear(),
        'validation_result' => json_encode([]),
        'signed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('verification_records')->where('envelope_id', $envelope->id)->update([
        'signature_status' => 'mixed',
        'validation_result' => json_encode([
            'result' => [
                'signature_count' => 2, 'all_intact' => true, 'all_valid' => true, 'all_trusted' => false,
                'trust_roots_configured' => 0, 'revocation' => 'not_checked',
                'signatures' => [['field_name' => 'AV-P1', 'intact' => true, 'valid' => true, 'trusted' => false]],
            ],
            'incremental_chain' => ['ok' => true],
        ]),
    ]);

    $public = ParticipantSignatureViews::forPublic($envelope->fresh());

    expect($public)->toHaveCount(1)
        ->and($public[0]['is_test'])->toBeTrue()
        ->and($public[0]['integrity_label'])->not->toContain('válida');
});

it('o selo da assinatura de participante com certificado de TESTE não diz "válida" em verde', function () {
    $source = (string) file_get_contents(base_path('resources/js/components/verification/crypto-signature-list.tsx'));
    $public = substr($source, (int) strpos($source, 'export function PublicParticipantSignatureList'));

    // O selo do cartão público usa o rótulo genérico, sem olhar `is_test`.
    $badgeStart = (int) strpos($public, '<Badge');
    $badge = substr($public, $badgeStart, (int) strpos($public, '</Badge>', $badgeStart) - $badgeStart);
    $labels = (string) file_get_contents(base_path('resources/js/lib/labels.ts'));

    $genericValid = str_contains($badge, 'cryptoIntegrityLabels[signature.integrity]')
        && preg_match("/intact:\s*'[^']*válida/u", $labels) === 1;

    expect($genericValid && ! str_contains($badge, 'is_test'))
        ->toBeFalse('Com certificado de TESTE o cartão público exibe o selo verde "Íntegra e válida" ao lado de "não tem validade jurídica".');
});
