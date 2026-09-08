<?php

namespace App\Enums;

/**
 * Finalidade de um recipient_access_link.
 */
enum AccessLinkPurpose: string
{
    case Signing = 'signing';
    case Download = 'download';

    public function label(): string
    {
        return match ($this) {
            self::Signing => 'Assinatura',
            self::Download => 'Download',
        };
    }
}
