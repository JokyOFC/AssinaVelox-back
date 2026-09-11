<?php

namespace App\Services\Impersonation;

use App\Enums\AuditEventType;
use App\Enums\MembershipStatus;
use App\Http\Middleware\EnforceImpersonationReadOnly;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Impersonation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AdminLog\OrganizationTrail;
use App\Services\AdminLog\PlatformAction;
use App\Services\AdminLog\PlatformTrail;
use App\Services\AdminLog\ToolFlags;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Acessar como" (Fase 2, roadmap §2.14 / ROUTES Q15).
 *
 * Início: platform admin, senha confirmada, motivo obrigatório, alvo com membership ATIVA na
 * organização, alvo que NÃO é platform admin nem está bloqueado. Troca a sessão para o alvo
 * (o admin continua registrado em `session('impersonation')`), com prazo de 30 minutos.
 *
 * Durante a sessão, App\Http\Middleware\EnforceImpersonationReadOnly libera só páginas de
 * leitura (lista fechada), registra cada página visitada e encerra ao expirar.
 *
 * Trilha: início, fim e cada página visitada entram na trilha DA ORGANIZAÇÃO ALVO
 * (`audit_events`, ator = o admin, `payload.impersonation`), para que a organização veja que
 * foi acessada; início e fim também entram em `platform_audit_events`.
 *
 * Termos de Uso: exige cláusula de consentimento (minuta em docs/juridico/termos-de-uso.md
 * §4.5 — ver docs/fase-2/tags-relatorios-e-logs.md §6).
 */
final class ImpersonationManager
{
    public const SESSION_KEY = 'impersonation';

    public const TTL_MINUTES = 30;

    public function start(User $admin, Organization $organization, User $target, string $reason, Request $request): Impersonation
    {
        $this->assertCanStart($admin, $organization, $target);

        $now = Carbon::now();
        $correlationId = (string) Str::ulid();

        /** @var Impersonation $impersonation */
        $impersonation = DB::transaction(function () use ($admin, $organization, $target, $reason, $request, $now, $correlationId): Impersonation {
            // Uma sessão por admin: qualquer sobra anterior é encerrada.
            Impersonation::query()
                ->where('admin_user_id', $admin->getKey())
                ->whereNull('ended_at')
                ->update(['ended_at' => $now, 'end_reason' => Impersonation::END_INVALID]);

            $impersonation = Impersonation::query()->create([
                'admin_user_id' => $admin->getKey(),
                'organization_id' => $organization->getKey(),
                'target_user_id' => $target->getKey(),
                'reason' => $reason,
                'started_at' => $now,
                'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);

            OrganizationTrail::record((int) $organization->getKey(), AuditEventType::ImpersonationStarted, [
                'impersonation' => $impersonation->ulid,
                'target_name' => $target->name,
                'reason' => $reason,
            ], $admin, $correlationId);

            PlatformTrail::record(PlatformAction::ImpersonationStarted, $admin, PlatformTrail::TARGET_ORGANIZATION, (int) $organization->getKey(), (int) $organization->getKey(), [
                'impersonation' => $impersonation->ulid,
                'organization_name' => $organization->name,
                'target_user' => (string) $target->getKey(),
                'target_name' => $target->name,
                'reason' => $reason,
            ], $correlationId);

            return $impersonation;
        });

        $session = $request->session();

        // Nada da sessão do admin (senha confirmada, organização escolhida) passa para o alvo.
        $session->forget(['auth.password_confirmed_at', 'url.intended']);

        Auth::guard('web')->login($target);

        $session->put(self::SESSION_KEY, [
            'id' => $impersonation->getKey(),
            'admin_id' => $admin->getKey(),
        ]);
        $session->put(EnsureCurrentOrganization::SESSION_KEY, $organization->getKey());
        $session->regenerateToken();

        return $impersonation;
    }

    /**
     * Encerra a sessão ativa e devolve o login ao admin (quando ele ainda é platform admin
     * sem bloqueio). Devolve o admin restaurado ou null (sessão encerrada por completo).
     */
    public function stop(Request $request, string $reason = Impersonation::END_STOPPED): ?User
    {
        $state = $request->session()->get(self::SESSION_KEY);
        $impersonation = is_array($state) && isset($state['id']) ? Impersonation::query()->find((int) $state['id']) : null;

        if ($impersonation !== null) {
            $this->end($impersonation, $reason);
        }

        $request->session()->forget([self::SESSION_KEY, EnsureCurrentOrganization::SESSION_KEY, 'auth.password_confirmed_at']);

        $admin = is_array($state) && isset($state['admin_id']) ? User::query()->find((int) $state['admin_id']) : null;

        if ($admin !== null && $admin->is_platform_admin && $admin->getAttribute('blocked_at') === null && $reason !== Impersonation::END_LOGOUT) {
            Auth::guard('web')->login($admin);
            $request->session()->regenerateToken();

            return $admin;
        }

        // `logout()` trocaria o `remember_token` de quem está autenticado — o ALVO — e
        // derrubaria todos os "lembrar de mim" do cliente por uma ação do suporte. Aqui só a
        // sessão atual é encerrada.
        $guard = Auth::guard('web');

        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        } else {
            $guard->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return null;
    }

    /**
     * Grava o fim (idempotente: um registro já encerrado não muda).
     */
    public function end(Impersonation $impersonation, string $reason): void
    {
        $now = Carbon::now();

        $updated = Impersonation::query()
            ->whereKey($impersonation->getKey())
            ->whereNull('ended_at')
            ->update(['ended_at' => $now, 'end_reason' => $reason, 'updated_at' => $now]);

        if ($updated === 0) {
            return;
        }

        $impersonation->refresh();
        $admin = User::query()->find($impersonation->admin_user_id);

        $payload = [
            'impersonation' => $impersonation->ulid,
            'end_reason' => $reason,
            'pages_viewed' => $impersonation->pages_viewed,
        ];

        OrganizationTrail::record($impersonation->organization_id, AuditEventType::ImpersonationEnded, $payload, $admin);
        PlatformTrail::record(PlatformAction::ImpersonationEnded, $admin, PlatformTrail::TARGET_ORGANIZATION, $impersonation->organization_id, $impersonation->organization_id, $payload);
    }

    /**
     * Sessão de impersonation da requisição, se houver (mesmo expirada — quem chama decide).
     */
    public function current(Request $request): ?Impersonation
    {
        if (! $request->hasSession()) {
            return null;
        }

        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ! isset($state['id'])) {
            return null;
        }

        return Impersonation::query()->with(['admin', 'targetUser', 'organization'])->find((int) $state['id']);
    }

    public static function active(Request $request): bool
    {
        return $request->hasSession() && is_array($request->session()->get(self::SESSION_KEY));
    }

    public function recordVisit(Impersonation $impersonation, Request $request): void
    {
        Impersonation::query()->whereKey($impersonation->getKey())->increment('pages_viewed');

        OrganizationTrail::record($impersonation->organization_id, AuditEventType::ImpersonationPageViewed, [
            'impersonation' => $impersonation->ulid,
            'route' => (string) $request->route()?->getName(),
            // Só o caminho (ULIDs, nunca a query string — que pode ter termos de busca).
            'path' => '/'.ltrim($request->path(), '/'),
        ], $impersonation->admin);
    }

    /**
     * @throws ValidationException
     */
    private function assertCanStart(User $admin, Organization $organization, User $target): void
    {
        abort_unless(ToolFlags::impersonation(), 404);
        abort_unless($admin->is_platform_admin, 403, 'Acesso restrito à equipe AssinaVelox.');
        abort_unless(EnforceImpersonationReadOnly::installed(), 503, 'A proteção de somente leitura não está instalada; "Acessar como" indisponível.');

        $fail = fn (string $message) => throw ValidationException::withMessages(['user' => $message]);

        if ($target->is($admin)) {
            $fail('Não é possível acessar como você mesmo.');
        }

        if ($target->is_platform_admin) {
            $fail('Não é permitido acessar como outro membro da equipe AssinaVelox.');
        }

        if ($target->getAttribute('blocked_at') !== null) {
            $fail('A conta deste usuário está bloqueada.');
        }

        $active = Membership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $target->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->exists();

        if (! $active) {
            $fail('O usuário não tem acesso ativo a esta organização.');
        }
    }
}
