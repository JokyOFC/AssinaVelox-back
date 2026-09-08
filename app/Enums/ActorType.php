<?php

namespace App\Enums;

enum ActorType: string
{
    case User = 'user';
    case Recipient = 'recipient';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Usuário',
            self::Recipient => 'Signatário',
            self::System => 'Sistema',
        };
    }
}
