<?php

namespace App\Enums;

enum DeliveryPurpose: string
{
    case Invitation = 'invitation';
    case Otp = 'otp';
    case Resend = 'resend';
    case Completed = 'completed';
    case Refused = 'refused';
    case Canceled = 'canceled';
    case MembershipInvitation = 'membership_invitation';

    public function label(): string
    {
        return match ($this) {
            self::Invitation => 'Convite para assinar',
            self::Otp => 'Código de verificação',
            self::Resend => 'Reenvio de convite',
            self::Completed => 'Documento concluído',
            self::Refused => 'Documento recusado',
            self::Canceled => 'Documento cancelado',
            self::MembershipInvitation => 'Convite para a organização',
        };
    }
}
