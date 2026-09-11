<?php

namespace App\Enums;

enum DeliveryPurpose: string
{
    case Invitation = 'invitation';
    case Otp = 'otp';
    case Resend = 'resend';
    case Expiring = 'expiring';
    case Completed = 'completed';
    case Refused = 'refused';
    case Canceled = 'canceled';
    case MembershipInvitation = 'membership_invitation';
    case Signed = 'signed';
    case DailyDigest = 'daily_digest';
    case InvitationAccepted = 'invitation_accepted';
    case ProductNews = 'product_news';

    // Fase 2 §2.5 (B-REM): lembrete automático ao signatário e aviso ao remetente de que o
    // envio agendado não pôde sair. Ver docs/fase-2/lembretes-e-agendamento.md.
    case Reminder = 'reminder';
    case ScheduledSend = 'scheduled_send';

    public function label(): string
    {
        return match ($this) {
            self::Invitation => 'Convite para assinar',
            self::Otp => 'Código de verificação',
            self::Resend => 'Reenvio de convite',
            self::Expiring => 'Aviso de prazo',
            self::Completed => 'Documento concluído',
            self::Refused => 'Documento recusado',
            self::Canceled => 'Documento cancelado',
            self::MembershipInvitation => 'Convite para a organização',
            self::Signed => 'Assinatura registrada',
            self::DailyDigest => 'Resumo diário de pendências',
            self::InvitationAccepted => 'Convite de usuário aceito',
            self::ProductNews => 'Novidades do produto',
            self::Reminder => 'Lembrete automático',
            self::ScheduledSend => 'Envio agendado',
        };
    }
}
