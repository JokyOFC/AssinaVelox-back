<?php

use App\Enums\DeliveryStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\PaymentStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Enums\SubscriptionStatus;
use App\Models\AuditEvent;
use App\Models\AuthChallenge;
use App\Models\CertificateReference;
use App\Models\DeliveryAttempt;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentWebhookReceipt;
use App\Models\Plan;
use App\Models\PlanConsumption;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningSession;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VerificationRecord;

it('cria todos os models pelas factories padrão', function (string $model) {
    $instance = $model::factory()->create();

    expect($instance)->toBeInstanceOf($model)
        ->and($instance->exists)->toBeTrue()
        ->and($model::query()->withoutGlobalScopes()->whereKey($instance->getKey())->exists())->toBeTrue();
})->with([
    User::class, Organization::class, Membership::class, MembershipInvitation::class, Folder::class,
    Envelope::class, Document::class, DocumentVersion::class, Recipient::class, RecipientAccessLink::class,
    SigningSession::class, AuthChallenge::class, SigningField::class, SigningFieldValue::class,
    SignatureAcceptance::class, AuditEvent::class, DeliveryAttempt::class, VerificationRecord::class,
    CertificateReference::class, Plan::class, Subscription::class, PlanConsumption::class, Payment::class,
    PaymentWebhookReceipt::class,
]);

it('faz cast dos atributos para enums PHP', function () {
    $envelope = Envelope::factory()->inProgress()->parallel()->create();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress)
        ->and($envelope->fresh()->signing_order)->toBe(SigningOrder::Parallel)
        ->and($envelope->verification_code)->toMatch('/^[A-HJ-NP-Z2-9]{12}$/')
        ->and($envelope->sent_at)->not->toBeNull();

    $recipient = Recipient::factory()->forEnvelope($envelope)->signed()->create();
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Signed)
        ->and($recipient->signed_at)->not->toBeNull();

    $document = Document::factory()->forEnvelope($envelope)->blocked()->create();
    expect($document->fresh()->processing_status)->toBe(DocumentProcessingStatus::Blocked)
        ->and($document->failure_code)->toBe('pdf_encrypted');

    $version = DocumentVersion::factory()->forDocument($document)->final(signed: true)->create();
    expect($version->fresh()->kind)->toBe(DocumentVersionKind::Final)
        ->and($version->has_signatures)->toBeTrue()
        ->and($version->pages_meta)->toBeArray()
        ->and($version->pageMeta(1))->toHaveKeys(['width_pt', 'height_pt', 'rotation', 'mediabox', 'cropbox']);

    $field = SigningField::factory()->forRecipient($recipient)->initials()->create();
    expect($field->fresh()->type)->toBe(FieldType::Initials)
        ->and((float) $field->x)->toBe(0.86)
        ->and($field->hasValidGeometry())->toBeTrue();
});

it('gera versões de documento com numeração sequencial por documento', function () {
    $document = Document::factory()->create();

    $v1 = DocumentVersion::factory()->forDocument($document)->original()->create();
    $v2 = DocumentVersion::factory()->forDocument($document)->converted()->create();
    $v3 = DocumentVersion::factory()->forDocument($document)->final()->create();

    expect([$v1->version_number, $v2->version_number, $v3->version_number])->toBe([1, 2, 3])
        ->and($document->latestVersion->is($v3))->toBeTrue()
        ->and($document->originalVersion->is($v1))->toBeTrue();
});

it('cria planos, assinaturas e pagamentos nos estados úteis', function () {
    $free = Plan::factory()->free()->create();
    $pro = Plan::factory()->professional()->create();

    expect($free->isFree())->toBeTrue()
        ->and($free->formatted_price)->toBe('Grátis')
        ->and($pro->formatted_price)->toBe('R$ 49,00')
        ->and($pro->is_sandbox)->toBeTrue()
        ->and($pro->is_public)->toBeFalse();

    $organization = Organization::factory()->create();
    $active = Subscription::factory()->forOrganization($organization)->ofPlan($pro)->active()->withUsage(10, 2)->create();
    $pastDue = Subscription::factory()->pastDue()->create();

    expect($active->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($active->remainingEnvelopes())->toBe(488)
        ->and($pastDue->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($pastDue->allowsSending())->toBeFalse()
        ->and($organization->currentSubscription->is($active))->toBeTrue();

    $approved = Payment::factory()->forSubscription($active)->approved()->create();
    $pending = Payment::factory()->forSubscription($active)->pending()->create();

    expect($approved->fresh()->status)->toBe(PaymentStatus::Approved)
        ->and($approved->display_status->value)->toBe('paid')
        ->and($approved->amount_cents)->toBe(4900)
        ->and($approved->isActivated())->toBeTrue()
        ->and($pending->isPaid())->toBeFalse()
        ->and($pending->external_reference)->toHaveLength(26);
});

it('cria entregas, sessões e desafios coerentes com o destinatário', function () {
    $recipient = Recipient::factory()->notified()->create();

    $delivery = DeliveryAttempt::factory()->forRecipient($recipient)->delivered()->create();
    $link = RecipientAccessLink::factory()->forRecipient($recipient)->create();
    $session = SigningSession::factory()->forAccessLink($link)->authenticated()->create();
    $challenge = AuthChallenge::factory()->forSession($session)->consumed()->create();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->to_address)->toBe($recipient->email)
        ->and($link->isUsable())->toBeTrue()
        ->and($link->envelope_id)->toBe($recipient->envelope_id)
        ->and($link->documentVersion)->not->toBeNull()
        ->and($session->isAuthenticated())->toBeTrue()
        ->and($session->hasValidAuthorization())->toBeTrue()
        ->and($session->organization_id)->toBe($recipient->organization_id)
        ->and($challenge->isConsumed())->toBeTrue()
        ->and($challenge->isVerifiable())->toBeFalse();
});
