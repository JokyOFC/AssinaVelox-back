<?php

namespace App\Services\Impersonation;

use App\Models\Impersonation;
use App\Models\User;
use App\Services\AdminLog\PlatformAction;
use App\Services\AdminLog\PlatformTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bloqueio de conta pelo painel interno. Bloquear:
 *  - grava motivo, autor e data em `users` (colunas aditivas);
 *  - derruba as sessões abertas (tabela `sessions`, driver database) e troca o
 *    `remember_token` — o "lembrar de mim" deixa de valer;
 *  - encerra sessões de "acessar como" que tinham a conta como alvo;
 *  - registra em `platform_audit_events`.
 * O efeito em toda requisição seguinte vem de App\Http\Middleware\EnsureAccountNotBlocked.
 *
 * Nunca bloqueia a si mesmo nem outro platform admin (isso é operação de infraestrutura,
 * fora do painel).
 */
final class AccountBlocking
{
    public function __construct(private readonly ImpersonationManager $impersonations) {}

    public function block(User $target, User $actor, string $reason): void
    {
        if ($target->is($actor)) {
            throw ValidationException::withMessages(['reason' => 'Você não pode bloquear a própria conta.']);
        }

        if ($target->is_platform_admin) {
            throw ValidationException::withMessages(['reason' => 'Contas da equipe AssinaVelox não são bloqueadas pelo painel.']);
        }

        if ($target->getAttribute('blocked_at') !== null) {
            throw ValidationException::withMessages(['reason' => 'Esta conta já está bloqueada.']);
        }

        DB::transaction(function () use ($target, $actor, $reason): void {
            $target->forceFill([
                'blocked_at' => Carbon::now(),
                'blocked_reason' => $reason,
                'blocked_by_user_id' => $actor->getKey(),
                'remember_token' => Str::random(60),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table((string) config('session.table', 'sessions'))
                    ->where('user_id', $target->getKey())
                    ->delete();
            }

            PlatformTrail::record(PlatformAction::UserBlocked, $actor, PlatformTrail::TARGET_USER, (int) $target->getKey(), null, [
                'target_name' => $target->name,
                'reason' => $reason,
            ]);
        });

        Impersonation::query()
            ->where('target_user_id', $target->getKey())
            ->whereNull('ended_at')
            ->get()
            ->each(fn (Impersonation $impersonation) => $this->impersonations->end($impersonation, Impersonation::END_INVALID));
    }

    public function unblock(User $target, User $actor, ?string $note = null): void
    {
        if ($target->getAttribute('blocked_at') === null) {
            throw ValidationException::withMessages(['reason' => 'Esta conta não está bloqueada.']);
        }

        DB::transaction(function () use ($target, $actor, $note): void {
            $target->forceFill([
                'blocked_at' => null,
                'blocked_reason' => null,
                'blocked_by_user_id' => null,
            ])->save();

            PlatformTrail::record(PlatformAction::UserUnblocked, $actor, PlatformTrail::TARGET_USER, (int) $target->getKey(), null, array_filter([
                'target_name' => $target->name,
                'reason' => $note,
            ], fn ($value) => $value !== null && $value !== ''));
        });
    }
}
