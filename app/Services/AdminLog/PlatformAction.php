<?php

namespace App\Services\AdminLog;

/**
 * Ações registradas em `platform_audit_events` (trilha da equipe da plataforma).
 * Valores estáveis — gravados em banco; nunca renomeie um caso.
 */
enum PlatformAction: string
{
    case UserBlocked = 'user.blocked';
    case UserUnblocked = 'user.unblocked';
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationEnded = 'impersonation.ended';
    case ImpersonationDenied = 'impersonation.denied';

    public function label(): string
    {
        return match ($this) {
            self::UserBlocked => 'Conta bloqueada',
            self::UserUnblocked => 'Conta desbloqueada',
            self::ImpersonationStarted => '"Acessar como" iniciado',
            self::ImpersonationEnded => '"Acessar como" encerrado',
            self::ImpersonationDenied => '"Acessar como" recusado',
        };
    }

    /**
     * @return 'ok'|'info'|'warn'
     */
    public function kind(): string
    {
        return match ($this) {
            self::UserBlocked, self::ImpersonationStarted, self::ImpersonationDenied => 'warn',
            self::UserUnblocked => 'ok',
            self::ImpersonationEnded => 'info',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $action): array => ['value' => $action->value, 'label' => $action->label()],
            self::cases(),
        );
    }
}
