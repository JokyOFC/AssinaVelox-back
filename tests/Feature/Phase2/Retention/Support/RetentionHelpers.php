<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de retenção e preservação (K-RET, Fase 2 §2.19)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\RetentionPolicy;
use App\Models\SigningField;
use App\Models\User;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Retention\RetentionCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Verification/Support/VerificationHelpers.php';

if (! function_exists('retentionEnable')) {
    /**
     * Liga `retention_policies`: configuração global E plano da organização.
     */
    function retentionEnable(Organization $organization): void
    {
        test()->withoutVite();
        config()->set('assinavelox.features.retention_policies', true);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['retention_policies'] = true;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('retentionPolicyFor')) {
    /**
     * Política gravada direto (sem a validação da tela).
     *
     * @param  array<string, int|null>  $periods
     */
    function retentionPolicyFor(Organization $organization, array $periods, bool $active = true): RetentionPolicy
    {
        $attributes = ['is_active' => $active];

        foreach (RetentionCategory::cases() as $category) {
            $attributes[$category->column()] = $periods[$category->value] ?? null;
        }

        $policy = RetentionPolicy::withoutOrganizationScope()->firstOrNew(['organization_id' => $organization->id]);
        $policy->forceFill($attributes + ['organization_id' => $organization->id])->save();

        return $policy;
    }
}

if (! function_exists('retentionFinishedEnvelope')) {
    /**
     * Envelope publicado e encerrado há `$daysAgo` dias, com bytes REAIS no disco (fake) para
     * todas as versões, a imagem da assinatura, a imagem de um campo e uma foto da captura,
     * mais um evento na trilha.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{envelope: Envelope, paths: list<string>, final_sha256: string|null, audit_event: AuditEvent, capture: IdentityCapture|null}
     */
    function retentionFinishedEnvelope(
        Organization $organization,
        User $owner,
        int $daysAgo = 2000,
        EnvelopeStatus $status = EnvelopeStatus::Completed,
        array $attributes = [],
    ): array {
        $envelope = verifiableEnvelope($organization, $owner, $status, attributes: $attributes);
        $record = $status === EnvelopeStatus::Completed ? finalizeEnvelope($envelope) : null;
        $at = now()->subDays($daysAgo);

        $column = match ($status) {
            EnvelopeStatus::Completed => 'completed_at',
            EnvelopeStatus::Refused => 'refused_at',
            EnvelopeStatus::Expired => 'expired_at',
            EnvelopeStatus::Canceled => 'canceled_at',
            default => 'updated_at',
        };

        DB::table('envelopes')->where('id', $envelope->id)->update([$column => $at, 'updated_at' => $at]);

        $disk = Storage::disk('documents');
        $paths = [];

        $documentIds = DB::table('documents')->where('envelope_id', $envelope->id)->pluck('id');

        foreach (DocumentVersion::withoutOrganizationScope()->whereIn('document_id', $documentIds)->get() as $version) {
            $disk->put($version->storage_path, '%PDF-1.7 '.$version->ulid);
            $paths[] = $version->storage_path;
        }

        $capture = null;
        $acceptance = DB::table('signature_acceptances')->where('envelope_id', $envelope->id)->first();

        if ($acceptance !== null) {
            $base = sprintf('orgs/%s/envelopes/%s', $organization->ulid, $envelope->ulid);

            $image = $base.'/signatures/signature-'.Str::ulid().'.png';
            $disk->put($image, 'PNG');
            DB::table('signature_acceptances')->where('id', $acceptance->id)->update(['signature_image_path' => $image]);
            $paths[] = $image;

            /** @var Recipient $recipient */
            $recipient = Recipient::withoutOrganizationScope()->findOrFail($acceptance->recipient_id);
            $version = DocumentVersion::withoutOrganizationScope()->findOrFail($envelope->sent_document_version_id);
            $field = SigningField::factory()->forRecipient($recipient, $version)->signature()->create();

            $fieldImage = $base.'/signatures/initials-'.Str::ulid().'.png';
            $disk->put($fieldImage, 'PNG');
            DB::table('signing_field_values')->insert([
                'signing_field_id' => $field->id,
                'recipient_id' => $recipient->id,
                'signature_acceptance_id' => $acceptance->id,
                'envelope_id' => $envelope->id,
                'organization_id' => $organization->id,
                'image_path' => $fieldImage,
                'created_at' => $at,
            ]);
            $paths[] = $fieldImage;

            $capturePath = $base.'/identity/selfie-'.Str::ulid().'.bin';
            $disk->put($capturePath, 'CIFRADO');
            $capture = new IdentityCapture;
            $capture->forceFill([
                'organization_id' => $organization->id,
                'envelope_id' => $envelope->id,
                'recipient_id' => $recipient->id,
                'signature_acceptance_id' => $acceptance->id,
                'kind' => 'selfie',
                'storage_path' => $capturePath,
                'sha256' => str_repeat('d', 64),
                'mime_type' => 'image/jpeg',
                'width' => 10,
                'height' => 10,
                'size_bytes' => 7,
                'source' => 'camera',
                'captured_at' => $at,
            ])->save();
            $paths[] = $capturePath;
        }

        $event = AuditEvent::query()->create([
            'organization_id' => $organization->id,
            'envelope_id' => $envelope->id,
            'actor_type' => ActorType::System,
            'event_type' => AuditEventType::EnvelopeCompleted,
            'payload' => ['envelope' => $envelope->ulid],
            'ip_address' => '203.0.113.9',
            'occurred_at' => $at,
        ]);

        return [
            'envelope' => $envelope->fresh(),
            'paths' => $paths,
            'final_sha256' => $record?->final_sha256,
            'audit_event' => $event,
            'capture' => $capture,
        ];
    }
}

if (! function_exists('retentionEnvelopeGone')) {
    /**
     * Nenhuma linha do envelope sobrou nas tabelas que a exclusão deve alcançar.
     */
    function retentionEnvelopeGone(Envelope $envelope): bool
    {
        $id = $envelope->id;

        foreach (['documents', 'recipients', 'signature_acceptances', 'signing_field_values', 'signing_fields', 'identity_captures', 'verification_records', 'delivery_attempts'] as $table) {
            if (DB::table($table)->where('envelope_id', $id)->exists()) {
                return false;
            }
        }

        return ! DB::table('envelopes')->where('id', $id)->exists();
    }
}
