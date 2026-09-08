<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Services\Organizations\CreateOrganization;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Criar organização (ROUTES §1.2 organizations.store / RECONCILIACAO Q5). Também é o destino
 * de quem está autenticado sem nenhuma membership ativa (middleware `org`).
 */
class OrganizationController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('organizations/create', [
            'timezones' => Timezones::options(),
            'hasOrganizations' => $request->user()->memberships()->exists(),
        ]);
    }

    public function store(StoreOrganizationRequest $request, CreateOrganization $createOrganization): RedirectResponse
    {
        $organization = $createOrganization->handle($request->user(), [
            'name' => $request->validated('name'),
            'legal_name' => $request->validated('legal_name'),
            'tax_id' => $request->validated('tax_id'),
            'timezone' => $request->validated('timezone'),
        ]);

        $request->session()->put(EnsureCurrentOrganization::SESSION_KEY, $organization->getKey());

        return redirect()
            ->route('dashboard')
            ->with('success', 'Organização "'.$organization->name.'" criada.');
    }
}
