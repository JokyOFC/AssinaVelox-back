<?php

namespace App\Services\Webhooks;

use App\Enums\AuditEventType;

/**
 * Catálogo de eventos publicáveis por webhook (docs/fase-2/webhooks.md §2). A fonte é a
 * trilha de auditoria (roadmap §2.16): cada tipo corresponde a UM `audit_events.event_type`.
 *
 * Os nomes são contrato público — nunca renomear; um significado novo ganha um tipo novo.
 * Semântica honesta (T1): `recipient.signed` é o ACEITE ELETRÔNICO registrado, não uma
 * assinatura com certificado; `recipient.viewed` é a abertura detectada do convite, não prova
 * de leitura. O que o arquivo final carrega de assinatura criptográfica vem só em
 * `envelope.completed` (`data.signature`, com o mesmo rótulo da verificação pública).
 */
enum WebhookEventType: string
{
    case EnvelopeSent = 'envelope.sent';
    case RecipientViewed = 'recipient.viewed';
    case RecipientSigned = 'recipient.signed';
    case RecipientApproved = 'recipient.approved';
    case RecipientRefused = 'recipient.refused';
    case EnvelopeRefused = 'envelope.refused';
    case EnvelopeCompleted = 'envelope.completed';
    case EnvelopeExpired = 'envelope.expired';
    case EnvelopeCanceled = 'envelope.canceled';
    case DocumentProcessingFailed = 'document.processing_failed';

    /** Evento de teste disparado pela tela ("Enviar teste"); não é assinável. */
    case Ping = 'webhook.ping';

    /** Assinatura de "todos os eventos" (inclui os que forem criados depois). */
    public const ALL = '*';

    public static function fromAudit(AuditEventType $type): ?self
    {
        return match ($type) {
            AuditEventType::EnvelopeSent => self::EnvelopeSent,
            AuditEventType::InvitationOpened => self::RecipientViewed,
            AuditEventType::AcceptanceRecorded => self::RecipientSigned,
            AuditEventType::ApprovalRecorded => self::RecipientApproved,
            AuditEventType::RecipientRefused => self::RecipientRefused,
            AuditEventType::EnvelopeRefused => self::EnvelopeRefused,
            AuditEventType::EnvelopeCompleted => self::EnvelopeCompleted,
            AuditEventType::EnvelopeExpired => self::EnvelopeExpired,
            AuditEventType::EnvelopeCanceled => self::EnvelopeCanceled,
            AuditEventType::DocumentProcessingFailed => self::DocumentProcessingFailed,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EnvelopeSent => 'Documento enviado para assinatura',
            self::RecipientViewed => 'Participante abriu o convite',
            self::RecipientSigned => 'Participante registrou o aceite eletrônico',
            self::RecipientApproved => 'Aprovador registrou a aprovação',
            self::RecipientRefused => 'Participante recusou',
            self::EnvelopeRefused => 'Documento encerrado por recusa',
            self::EnvelopeCompleted => 'Documento concluído',
            self::EnvelopeExpired => 'Documento expirado',
            self::EnvelopeCanceled => 'Documento cancelado',
            self::DocumentProcessingFailed => 'Falha no processamento do arquivo',
            self::Ping => 'Evento de teste',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EnvelopeSent => 'O envelope saiu para os participantes.',
            self::RecipientViewed => 'Abertura detectada do link do convite (não é prova de leitura).',
            self::RecipientSigned => 'Aceite eletrônico com evidências. Não é assinatura com certificado.',
            self::RecipientApproved => 'Um aprovador aprovou o documento.',
            self::RecipientRefused => 'Um participante recusou. O motivo não é enviado.',
            self::EnvelopeRefused => 'O envelope foi encerrado por causa de uma recusa.',
            self::EnvelopeCompleted => 'Todos concluíram; o arquivo final está disponível. Traz o rótulo honesto da assinatura.',
            self::EnvelopeExpired => 'O prazo terminou com pendências.',
            self::EnvelopeCanceled => 'O remetente cancelou o envelope.',
            self::DocumentProcessingFailed => 'O arquivo enviado não pôde ser processado.',
            self::Ping => 'Enviado pelo botão "Enviar teste". Nenhum documento envolvido.',
        };
    }

    public function isRecipientEvent(): bool
    {
        return in_array($this, [self::RecipientViewed, self::RecipientSigned, self::RecipientApproved, self::RecipientRefused], true);
    }

    /**
     * @return list<self>
     */
    public static function subscribable(): array
    {
        $types = [];

        foreach (self::cases() as $type) {
            if ($type !== self::Ping) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    public static function subscribableValues(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::subscribable());
    }
}
