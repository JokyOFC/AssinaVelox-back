<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de verificação pública e evidências (B-VERIFY)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\CertificateReference;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\User;
use App\Models\VerificationRecord;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Testing\TestResponse;

if (! function_exists('verifiableEnvelope')) {
    /**
     * Envelope enviado, com documento processado, versão congelada no envio e signatários.
     *
     * @param  list<array{name: string, email: string, signed?: bool, role_label?: string}>  $recipients
     */
    function verifiableEnvelope(
        Organization $organization,
        User $owner,
        EnvelopeStatus $status = EnvelopeStatus::Completed,
        array $recipients = [['name' => 'Maria Aparecida Silva', 'email' => 'maria@exemplo.test', 'signed' => true]],
        array $attributes = [],
    ): Envelope {
        $state = match ($status) {
            EnvelopeStatus::Completed => 'completed',
            EnvelopeStatus::Refused => 'refused',
            EnvelopeStatus::Expired => 'expired',
            EnvelopeStatus::Finalizing => 'finalizing',
            EnvelopeStatus::Canceled => 'canceled',
            default => 'inProgress',
        };

        $envelope = Envelope::factory()
            ->forOrganization($organization, $owner)
            ->{$state}()
            ->create(array_merge(['title' => 'Contrato de locação residencial'], $attributes));

        // `canceled()` da factory não passa por inProgress: um envelope cancelado depois do
        // envio precisa de sent_at e código, senão não é publicável.
        if ($envelope->verification_code === null || $envelope->sent_at === null) {
            $envelope->forceFill([
                'sent_at' => $envelope->sent_at ?? now()->subDays(3),
                'verification_code' => $envelope->verification_code ?? Envelope::generateVerificationCode(),
            ])->save();
        }

        $document = Document::factory()->forEnvelope($envelope)->create([
            'processing_status' => DocumentProcessingStatus::Ready,
            'page_count' => 4,
        ]);

        $original = DocumentVersion::factory()->forDocument($document)->original()->create([
            'page_count' => 4,
            'pages_meta' => DocumentVersionFactory::pagesMeta(4),
        ]);

        $sent = DocumentVersion::factory()->forDocument($document)->converted()->create([
            'page_count' => 4,
            'pages_meta' => DocumentVersionFactory::pagesMeta(4),
        ]);

        $document->forceFill(['current_version_id' => $sent->id])->save();
        $envelope->forceFill(['sent_document_version_id' => $sent->id])->save();

        foreach (array_values($recipients) as $index => $row) {
            $recipient = Recipient::factory()
                ->forEnvelope($envelope, $index + 1)
                ->{($row['signed'] ?? false) ? 'signed' : 'notified'}()
                ->create([
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'role_label' => $row['role_label'] ?? null,
                ]);

            if ($row['signed'] ?? false) {
                SignatureAcceptance::factory()->forRecipient($recipient)->create([
                    'document_version_id' => $sent->id,
                    'document_sha256' => $sent->sha256,
                    'ip_address' => '203.0.113.77',
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36',
                ]);
            }
        }

        return $envelope->fresh(['recipients', 'document']);
    }
}

if (! function_exists('finalizeEnvelope')) {
    /**
     * Simula o que o pipeline de finalização grava: versão final, evidências e o registro
     * público de verificação. (B-VERIFY não executa o pipeline; programa contra o schema.)
     */
    function finalizeEnvelope(
        Envelope $envelope,
        string $signatureStatus = 'none',
        ?CertificateReference $certificate = null,
        ?array $validationResult = null,
    ): VerificationRecord {
        $document = $envelope->document;

        $evidence = DocumentVersion::factory()->forDocument($document)->evidence()->create();
        $consolidated = DocumentVersion::factory()->forDocument($document)->consolidated()->create();
        $final = DocumentVersion::factory()->forDocument($document)->final($signatureStatus === 'company_a1')->create([
            'page_count' => 5,
            'pages_meta' => DocumentVersionFactory::pagesMeta(5),
        ]);

        $envelope->forceFill(['final_document_version_id' => $final->id])->save();

        $original = DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->where('kind', DocumentVersionKind::Original->value)
            ->firstOrFail();

        $sent = DocumentVersion::withoutOrganizationScope()
            ->whereKey($envelope->sent_document_version_id)
            ->firstOrFail();

        return VerificationRecord::query()->create([
            'code' => $envelope->verification_code,
            'envelope_id' => $envelope->getKey(),
            'organization_id' => $envelope->organization_id,
            'final_document_version_id' => $final->id,
            'original_sha256' => $original->sha256,
            'sent_sha256' => $sent->sha256,
            'consolidated_sha256' => $consolidated->sha256,
            'final_sha256' => $final->sha256,
            'signature_status' => $signatureStatus,
            'signature_profile' => $signatureStatus === 'company_a1' ? 'PAdES-B-B' : null,
            'certificate_reference_id' => $certificate?->getKey(),
            'validation_result' => $validationResult,
            'validated_at' => $signatureStatus === 'company_a1' ? now() : null,
        ]);
    }
}

if (! function_exists('validationSummary')) {
    /**
     * Resumo no formato de App\Services\Pdf\Dto\ValidationResult::summary(), que é o que a
     * finalização grava em `verification_records.validation_result`.
     *
     * @return array<string, mixed>
     */
    function validationSummary(bool $intact = true, int $trustRoots = 0, bool $trusted = false, bool $covering = true): array
    {
        return [
            'signature_count' => 1,
            'all_intact' => $intact,
            'all_valid' => $intact,
            // A `pdftool validate` real devolve estes dois agregados junto dos outros: sem
            // eles a plataforma não pode afirmar "nada mudou depois da assinatura", porque
            // `intact` não olha para bytes fora da revisão assinada.
            'all_covering' => $covering,
            'all_docmdp_ok' => $covering,
            'all_trusted' => $trusted,
            'trust_roots_configured' => $trustRoots,
            'revocation' => 'not_checked',
            'signatures' => [[
                'field_name' => 'Signature1',
                'intact' => $intact,
                'valid' => $intact,
                'trusted' => $trusted,
                'trust_reason' => $trustRoots === 0 ? 'no_trust_roots_configured' : null,
                'signer_subject' => 'CN=AssinaVelox Teste, O=AssinaVelox, C=BR',
                'issuer' => 'CN=AssinaVelox Test CA, O=AssinaVelox, C=BR',
                'coverage' => $covering ? 'ENTIRE_FILE' : 'ENTIRE_REVISION',
                'docmdp_ok' => $covering,
                'errors' => [],
            ]],
        ];
    }
}

if (! function_exists('verifyProps')) {
    /**
     * @return array<string, mixed>
     */
    function verifyProps(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
