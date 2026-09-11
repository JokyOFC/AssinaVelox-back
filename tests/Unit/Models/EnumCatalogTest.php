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

    // Cobrança (incremento 5): eventos da ORGANIZAÇÃO, com `envelope_id` nulo. Não
    // constam de RECONCILIACAO §3, que enumerou apenas o ciclo do envelope; entraram
    // porque a ativação de plano, o cancelamento e a inadimplência precisam de trilha
    // auditável (docs/cobranca.md).
    // `subscription.renewed` entrou na revisão adversarial: o ciclo do plano Grátis não
    // gera pagamento, logo não passa por `subscription.activated`, e sem trilha própria a
    // renovação da cota seria invisível (docs/cobranca.md §7).
    $billing = [
        'payment.created', 'payment.approved', 'payment.failed',
        'subscription.activated', 'subscription.canceled', 'subscription.resumed',
        'subscription.past_due', 'subscription.expired', 'subscription.renewed',
    ];

    // `document.presented` entrou na revisão final da Fase 1. A declaração de aceite afirma
    // que o conteúdo foi APRESENTADO nesta tela, e a trilha não tinha como sustentá-lo: ia
    // de `invitation.opened` — que a página de evidências rotula "não comprova leitura" —
    // direto para `acceptance.recorded`. O evento registra a ENTREGA dos bytes àquela
    // sessão de assinatura, não a leitura (docs/entrega-fase-1.md §10.3).
    $review = ['document.presented'];

    // Fase 2, onda A (roadmap §1 T7: eventos novos entram no catálogo, nada é renomeado).
    // Registro por área em docs/fase-2/*.md.
    $phase2 = [
        // Vários documentos e papéis (docs/fase-2/multi-documento-e-papeis.md).
        'documents.reordered', 'approval.recorded', 'document.finalized',
        // Lembretes e envio agendado (docs/fase-2/lembretes-e-agendamento.md).
        'envelope.scheduled', 'envelope.schedule_canceled', 'reminder.sent', 'reminder.skipped',
        // Funções personalizadas, times e acesso por pasta (docs/fase-2/permissoes-e-times.md).
        'role.created', 'role.updated', 'role.deleted', 'membership.role_changed',
        'team.created', 'team.updated', 'team.deleted', 'folder_access.updated',
        // Modelos (docs/fase-2/modelos.md).
        'template.created', 'template.updated', 'template.version_created', 'template.duplicated',
        'template.archived', 'template.restored', 'template.used',
        // Etiquetas, relatórios e "acessar como" (docs/fase-2/tags-relatorios-e-logs.md).
        'tag.created', 'tag.updated', 'tag.deleted', 'tags.applied', 'tags.removed',
        'report.exported', 'impersonation.started', 'impersonation.ended', 'impersonation.page_viewed',
    ];

    expect(AuditEventType::values())
        ->toEqualCanonicalizing([...$expected, ...$billing, ...$review, ...$phase2])
        ->toHaveCount(45 + 31);

    // A lista da reconciliação continua inteira: nada foi renomeado nem removido.
    expect(array_intersect($expected, AuditEventType::values()))->toHaveCount(35);
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
