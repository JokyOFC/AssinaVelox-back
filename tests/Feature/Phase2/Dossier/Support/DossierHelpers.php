<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do dossiê (K-TSA)
|--------------------------------------------------------------------------
| Monta um envelope CONCLUÍDO com bytes reais no disco (original, consolidado, evidências e
| final), aceite gravado, trilha e registro de verificação — sem depender do finalizador,
| para isolar o dossiê do pipeline de finalização.
*/

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Enums\SignatureStatus;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\User;
use App\Models\VerificationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Finalization/Support/FinalizationHelpers.php';
require_once __DIR__.'/../../Timestamp/Support/TimestampHelpers.php';

const KTSA_RAW_ACCESS_TOKEN = 'convite-token-bruto-NAO-PODE-VAZAR-7f3a';
const KTSA_PAYLOAD_SECRET = 'segredo-no-payload-NAO-PODE-VAZAR-91c2';

if (! function_exists('ktsaCompletedEnvelope')) {
    /**
     * @return array{envelope: Envelope, document: Document, versions: array<string, DocumentVersion>, recipient: Recipient}
     */
    function ktsaCompletedEnvelope(Organization $organization, User $owner, string $work, string $title = 'Contrato de locação'): array
    {
        $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create([
            'title' => $title,
            'terms_version' => 'v1-2026-09-08',
        ]);

        $document = Document::factory()->forEnvelope($envelope)->create([
            'processing_status' => DocumentProcessingStatus::Ready,
            'name' => 'contrato',
            'original_filename' => 'contrato.pdf',
            'position' => 1,
        ]);

        $versions = [];
        $number = 0;

        foreach ([DocumentVersionKind::Original, DocumentVersionKind::Consolidated, DocumentVersionKind::Evidence, DocumentVersionKind::Final] as $kind) {
            $number++;
            $source = PdfFixtures::onePagePdf($work.DIRECTORY_SEPARATOR.Str::ulid().'.pdf', $title.' — '.$kind->value);
            $bytes = (string) file_get_contents($source);
            $ulid = (string) Str::ulid();
            $path = sprintf('orgs/%s/envelopes/%s/%s.pdf', $organization->ulid, $envelope->ulid, $ulid);
            Storage::disk('documents')->put($path, $bytes);

            $versions[$kind->value] = DocumentVersion::factory()->forDocument($document)->create([
                'ulid' => $ulid,
                'version_number' => $number,
                'kind' => $kind,
                'storage_path' => $path,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'page_count' => 1,
                'has_signatures' => false,
                'created_by_type' => ActorType::System,
            ]);
        }

        $document->forceFill([
            'current_version_id' => $versions['original']->getKey(),
            'sent_version_id' => $versions['original']->getKey(),
            'final_version_id' => $versions['final']->getKey(),
        ])->save();

        $envelope->forceFill([
            'status' => EnvelopeStatus::Completed,
            'sent_at' => now()->subDays(2),
            'completed_at' => now()->subHour(),
            'sent_document_version_id' => $versions['original']->getKey(),
            'final_document_version_id' => $versions['final']->getKey(),
            'verification_code' => Envelope::generateVerificationCode(),
        ])->save();

        $recipient = Recipient::factory()->forEnvelope($envelope, 1)->create([
            'name' => 'Maria Alves Souza',
            'email' => 'maria.alves@exemplo.test',
            'status' => RecipientStatus::Signed,
            'signed_at' => now()->subHours(2),
        ]);

        finalizationRecordAcceptance($envelope, $recipient, $versions['original'], [], ['name' => $recipient->name], 0);

        $base = ['organization_id' => $organization->getKey(), 'envelope_id' => $envelope->getKey()];
        AuditEvent::query()->create([...$base, 'actor_type' => ActorType::User, 'actor_id' => $owner->getKey(), 'event_type' => AuditEventType::EnvelopeSent, 'payload' => ['recipients' => 1], 'occurred_at' => now()->subDays(2)]);
        AuditEvent::query()->create([
            ...$base,
            'recipient_id' => $recipient->getKey(),
            'actor_type' => ActorType::Recipient,
            'actor_id' => $recipient->getKey(),
            'event_type' => AuditEventType::AcceptanceRecorded,
            // Payload hostil: segredo com cara de token e texto que vira fórmula no Excel.
            'payload' => ['document_sha256' => $versions['original']->sha256, 'token' => KTSA_PAYLOAD_SECRET, 'reason' => '=HYPERLINK("http://mal.example","clique")'],
            'ip_address' => '203.0.113.10',
            // Célula que o Excel trataria como fórmula se não fosse neutralizada.
            'user_agent' => '=HYPERLINK("http://mal.example","clique")',
            'occurred_at' => now()->subHours(2),
        ]);
        AuditEvent::query()->create([...$base, 'actor_type' => ActorType::System, 'event_type' => AuditEventType::EnvelopeCompleted, 'occurred_at' => now()->subHour()]);

        // Segredos reais que existem no banco para este envelope e não podem sair no ZIP.
        DB::table('recipient_access_links')->insert([
            'ulid' => (string) Str::ulid(),
            'recipient_id' => $recipient->getKey(),
            'envelope_id' => $envelope->getKey(),
            'document_version_id' => $versions['original']->getKey(),
            'organization_id' => $organization->getKey(),
            // Um token por envelope (token_digest é UNIQUE); o do cenário padrão é a constante varrida.
            'token_digest' => hash('sha256', $title === 'Contrato de locação' ? KTSA_RAW_ACCESS_TOKEN : KTSA_RAW_ACCESS_TOKEN.'-'.$envelope->ulid),
            'purpose' => 'signing',
            'created_at' => now(),
        ]);

        VerificationRecord::query()->create([
            'code' => $envelope->verification_code,
            'envelope_id' => $envelope->getKey(),
            'organization_id' => $organization->getKey(),
            'final_document_version_id' => $versions['final']->getKey(),
            'original_sha256' => $versions['original']->sha256,
            'sent_sha256' => $versions['original']->sha256,
            'consolidated_sha256' => $versions['consolidated']->sha256,
            'final_sha256' => $versions['final']->sha256,
            'signature_status' => SignatureStatus::None,
            'signature_profile' => null,
            'validation_result' => ['signed' => false, 'profile' => null, 'reason' => 'signer_not_configured', 'result' => null],
            'validated_at' => now(),
        ]);

        return ['envelope' => $envelope->fresh(), 'document' => $document->fresh(), 'versions' => $versions, 'recipient' => $recipient];
    }
}

if (! function_exists('ktsaDossierSetup')) {
    /**
     * Organização com dono, flag do dossiê ligada e disco `documents` exclusivo.
     *
     * @return array{organization: Organization, owner: User}
     */
    function ktsaDossierSetup(string $work): array
    {
        finalizationDisk($work.DIRECTORY_SEPARATOR.'disco-documents');
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte Consultoria']);
        ktsaEnableDossier($organization);

        return ['organization' => $organization, 'owner' => $owner];
    }
}
