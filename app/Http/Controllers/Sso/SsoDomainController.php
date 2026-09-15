<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SsoDomain;
use App\Models\User;
use App\Services\Sso\Domains\SsoDomainManager;
use App\Services\Sso\SsoFailure;
use App\Services\Sso\SsoFeature;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Domínios do login corporativo (docs/fase-3/sso.md §4): adicionar, verificar por TXT, remover.
 */
class SsoDomainController extends Controller
{
    public function __construct(private readonly SsoDomainManager $domains) {}

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization();
        $data = $request->validate(['domain' => ['required', 'string', 'max:253']], [], ['domain' => 'domínio']);

        try {
            $this->domains->add($organization, (string) $data['domain'], $this->user($request));
        } catch (SsoFailure $failure) {
            return back()->withErrors(['domain' => $failure->userMessage()]);
        }

        return back()->with('success', 'Domínio adicionado. Crie o registro TXT indicado e clique em "Verificar".');
    }

    public function verify(Request $request, SsoDomain $domain): RedirectResponse
    {
        $this->organization();

        try {
            $this->domains->verify($domain, $this->user($request));
        } catch (SsoFailure $failure) {
            return back()->withErrors(['verify' => $failure->userMessage()]);
        }

        return back()->with('success', 'Domínio verificado.');
    }

    public function destroy(Request $request, SsoDomain $domain): RedirectResponse
    {
        $this->organization();
        $this->domains->remove($domain, $this->user($request));

        return back()->with('success', 'Domínio removido. Quem usa esse domínio deixa de entrar pelo login corporativo.');
    }

    private function organization(): Organization
    {
        $organization = CurrentOrganization::instance()->get();

        abort_if($organization === null || ! SsoFeature::anyFor($organization), 404);
        Gate::authorize('updateSettings', $organization);

        return $organization;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
