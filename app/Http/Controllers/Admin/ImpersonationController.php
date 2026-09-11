<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Impersonation;
use App\Models\Organization;
use App\Models\User;
use App\Services\AdminLog\ToolFlags;
use App\Services\Impersonation\ImpersonationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Acessar como" (ROUTES §1.5 `admin.organizations.impersonate` / Q15; Fase 2 §2.14).
 *
 * `store`: platform admin + flag `impersonation` + senha digitada na hora (regra
 * `current_password`, não apenas a janela de `password.confirm`) + motivo. O controller
 * delega as regras (alvo não é platform admin, tem membership ativa, não está bloqueado) a
 * ImpersonationManager.
 *
 * `stop` (`admin.impersonation.stop`): fica FORA do grupo platform-admin — durante a
 * sessão o usuário autenticado é o alvo. Só encerra a impersonation da própria sessão.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationManager $manager) {}

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless(ToolFlags::impersonation(), 404);

        $validated = $request->validate([
            'user' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'password' => ['required', 'string', 'current_password:web'],
        ], [
            'password.current_password' => 'Senha incorreta.',
        ], ['user' => 'usuário', 'reason' => 'motivo', 'password' => 'senha']);

        $target = User::query()->findOrFail((int) $validated['user']);

        $this->manager->start($request->user(), $organization, $target, trim((string) $validated['reason']), $request);

        return redirect()->route('dashboard')->with('info', 'Você está acessando como '.$target->name.' (somente leitura, até '.ImpersonationManager::TTL_MINUTES.' minutos).');
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonation = $this->manager->current($request);

        if ($impersonation === null) {
            return redirect()->route('dashboard');
        }

        $admin = $this->manager->stop($request, Impersonation::END_STOPPED);

        if ($admin === null) {
            return redirect()->route('login');
        }

        return redirect()
            ->route('admin.organizations.show', ['organization' => $impersonation->organization->ulid])
            ->with('success', 'Acesso de suporte encerrado.');
    }
}
