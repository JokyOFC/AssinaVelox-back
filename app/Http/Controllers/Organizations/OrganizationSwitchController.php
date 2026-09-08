<?php

namespace App\Http\Controllers\Organizations;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /organizacoes/{organization}/ativar: troca a organização corrente. Só para membership
 * ATIVA do usuário (suspensa ou inexistente → 403).
 */
class OrganizationSwitchController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $membership = $request->user()->membershipFor($organization);

        abort_if($membership === null || $membership->status !== MembershipStatus::Active, 403, 'Você não participa desta organização.');

        $request->session()->put(EnsureCurrentOrganization::SESSION_KEY, $organization->getKey());
        $request->user()->forceFill(['current_organization_id' => $organization->getKey()])->save();

        return redirect()->route('dashboard');
    }
}
