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
    // Fase 3 §3.7 — antifraude (P3-RISK, docs/fase-3/antifraude.md). `risk.status_changed` sem
    // ator = transição automática pelas regras; com ator = decisão humana.
    case RiskStatusChanged = 'risk.status_changed';
    case RiskReviewDecided = 'risk.review_decided';
    case RiskReviewRequested = 'risk.review_requested';
    // Catálogo de planos pelo painel interno (docs/cobranca.md §18).
    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';

    public function label(): string
    {
        return match ($this) {
            self::UserBlocked => 'Conta bloqueada',
            self::UserUnblocked => 'Conta desbloqueada',
            self::ImpersonationStarted => '"Acessar como" iniciado',
            self::ImpersonationEnded => '"Acessar como" encerrado',
            self::ImpersonationDenied => '"Acessar como" recusado',
            self::RiskStatusChanged => 'Estado de risco da organização alterado',
            self::RiskReviewDecided => 'Caso de antifraude decidido',
            self::RiskReviewRequested => 'Revisão de antifraude pedida pela organização',
            self::PlanCreated => 'Plano criado',
            self::PlanUpdated => 'Plano alterado',
        };
    }

    /**
     * @return 'ok'|'info'|'warn'
     */
    public function kind(): string
    {
        return match ($this) {
            self::UserBlocked, self::ImpersonationStarted, self::ImpersonationDenied, self::RiskStatusChanged => 'warn',
            self::UserUnblocked => 'ok',
            self::ImpersonationEnded, self::RiskReviewDecided, self::RiskReviewRequested, self::PlanCreated, self::PlanUpdated => 'info',
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
