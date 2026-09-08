<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\MembershipRole;
use App\Enums\PaymentDisplayStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;

it('agrupa os status do Mercado Pago em status de exibição', function () {
    $expected = [
        'approved' => 'paid',
        'authorized' => 'paid',
        'pending' => 'pending',
        'in_process' => 'pending',
        'in_mediation' => 'pending',
        'rejected' => 'failed',
        'refunded' => 'refunded',
        'charged_back' => 'refunded',
        'cancelled' => 'cancelled',
    ];

    foreach ($expected as $status => $display) {
        expect(PaymentDisplayStatus::fromPaymentStatus(PaymentStatus::from($status))->value)->toBe($display, $status);
    }

    expect(PaymentStatus::Approved->label())->toBe('Paga')
        ->and(PaymentStatus::Approved->isPaid())->toBeTrue()
        ->and(PaymentStatus::Pending->isTerminal())->toBeFalse()
        ->and(PaymentStatus::Rejected->isTerminal())->toBeTrue();
});

it('contém todos os tipos de evento de auditoria da reconciliação', function () {
    $expected = [
        'envelope.created', 'envelope.updated', 'document.uploaded', 'document.conversion_started',
        'document.converted', 'document.processing_failed', 'document.blocked', 'document.removed',
        'fields.updated', 'recipients.updated', 'envelope.sent', 'invitation.sent', 'invitation.resent',
        'invitation.opened', 'challenge.sent', 'challenge.verified', 'challenge.failed', 'session.started',
        'acceptance.recorded', 'recipient.refused', 'envelope.refused', 'envelope.expired', 'envelope.canceled',
        'envelope.finalizing', 'envelope.consolidated', 'envelope.evidence_generated', 'envelope.signed_company_a1',
        'envelope.completed', 'envelope.finalization_failed', 'envelope.downloaded', 'envelope.moved',
        'envelope.duplicated', 'plan.consumption_reserved', 'plan.consumption_committed', 'plan.consumption_released',
    ];

    expect(AuditEventType::values())->toEqualCanonicalizing($expected)->toHaveCount(35);
});

it('deriva o tom (kind) dos eventos para a UI', function () {
    expect(AuditEventType::AcceptanceRecorded->kind())->toBe('ok')
        ->and(AuditEventType::EnvelopeCompleted->kind())->toBe('ok')
        ->and(AuditEventType::ChallengeVerified->kind())->toBe('ok')
        ->and(AuditEventType::RecipientRefused->kind())->toBe('warn')
        ->and(AuditEventType::EnvelopeExpired->kind())->toBe('warn')
        ->and(AuditEventType::ChallengeFailed->kind())->toBe('warn')
        ->and(AuditEventType::DocumentProcessingFailed->kind())->toBe('warn')
        ->and(AuditEventType::EnvelopeSent->kind())->toBe('info')
        ->and(AuditEventType::InvitationOpened->kind())->toBe('info');

    foreach (AuditEventType::cases() as $type) {
        expect($type->label())->not->toBe('');
    }
});

it('define rótulos em português nos demais enums', function () {
    expect(DocumentProcessingStatus::Blocked->label())->toBe('Bloqueado')
        ->and(DocumentProcessingStatus::Converting->label())->toBe('Convertendo…')
        ->and(MembershipRole::Owner->label())->toBe('Proprietário')
        ->and(MembershipRole::Admin->label())->toBe('Administrador')
        ->and(MembershipRole::Member->label())->toBe('Operador')
        ->and(MembershipRole::Owner->isAtLeast(MembershipRole::Admin))->toBeTrue()
        ->and(MembershipRole::Member->canManageMembers())->toBeFalse()
        ->and(SubscriptionStatus::PastDue->label())->toBe('Inadimplente')
        ->and(SubscriptionStatus::PastDue->allowsSending())->toBeFalse()
        ->and(SubscriptionStatus::Active->allowsSending())->toBeTrue();
});
