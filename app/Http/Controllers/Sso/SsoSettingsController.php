<?php

namespace App\Http\Controllers\Sso;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Integrations\Sso\Saml\SamlMetadataImporter;
use App\Models\Organization;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\Sso\OidcLoginFlow;
use App\Services\Sso\SsoConnectionManager;
use App\Services\Sso\SsoConnectionStatus;
use App\Services\Sso\SsoFailure;
use App\Services\Sso\SsoFeature;
use App\Services\Sso\SsoLoginCompleter;
use App\Services\Sso\SsoPresenter;
use App\Services\Sso\SsoProtocol;
use App\Services\Sso\SsoRedirector;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Configurações › Login único (SSO) — docs/fase-3/sso.md §10. Rotas sob `org` +
 * `org.role:owner,admin` e Policy `updateSettings`. Com as duas flags desligadas para a
 * organização: 404 em tudo. Só o owner liga/desliga o SSO obrigatório.
 */
class SsoSettingsController extends Controller
{
    public function __construct(
        private readonly SsoConnectionManager $manager,
        private readonly SsoPresenter $presenter,
        private readonly SsoRedirector $redirector,
        private readonly SsoLoginCompleter $completer,
    ) {}

    public function edit(Request $request): Response
    {
        $organization = $this->organization();

        return Inertia::render('settings/sso', $this->presenter->forSettings(
            $organization,
            $this->isOwner(),
            $this->user($request)->hasTwoFactorEnabled(),
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization();

        // Conectar o provedor é decisão do owner, como mudar os dados dele (revisão G).
        if (! $this->isOwner()) {
            return back()->withErrors(['protocol' => SsoConnectionManager::OWNER_ONLY_MESSAGE]);
        }

        $data = $request->validate($this->rules($request->input('protocol') === SsoProtocol::Saml->value ? SsoProtocol::Saml : SsoProtocol::Oidc, true));

        try {
            $this->manager->create($organization, $data, $this->user($request));
        } catch (SsoFailure $failure) {
            return back()->withErrors([$failure->field ?? 'protocol' => $failure->userMessage()])->withInput($request->except('oidc_client_secret'));
        }

        return back()->with('success', 'Conexão salva. Verifique um domínio e teste a conexão antes de ativá-la.');
    }

    public function update(Request $request, SsoConnection $connection): RedirectResponse
    {
        $this->organization();
        $data = $request->validate($this->rules($connection->protocol, false));

        try {
            $this->manager->update($connection, $data, $this->user($request), $this->isOwner());
        } catch (SsoFailure $failure) {
            return back()->withErrors([$failure->field ?? 'name' => $failure->userMessage()]);
        }

        return back()->with('success', 'Alterações salvas.');
    }

    public function status(Request $request, SsoConnection $connection): RedirectResponse
    {
        $this->organization();
        $data = $request->validate(['status' => ['required', Rule::in([SsoConnectionStatus::Active->value, SsoConnectionStatus::Disabled->value])]]);

        try {
            $this->manager->setStatus($connection, SsoConnectionStatus::from($data['status']), $this->user($request), $this->isOwner());
        } catch (SsoFailure $failure) {
            return back()->withErrors(['status' => $failure->userMessage()]);
        }

        return back()->with('success', $data['status'] === SsoConnectionStatus::Active->value
            ? 'Login corporativo ativado.'
            : 'Login corporativo desativado. Todos voltam a entrar com e-mail e senha.');
    }

    public function destroy(Request $request, SsoConnection $connection): RedirectResponse
    {
        $this->organization();
        $this->manager->delete($connection, $this->user($request));

        return back()->with('success', 'Conexão removida. Todos voltam a entrar com e-mail e senha.');
    }

    public function test(Request $request, SsoConnection $connection): SymfonyResponse
    {
        $organization = $this->organization();

        if (! SsoFeature::enabledFor($connection->protocol, $organization)) {
            return back()->withErrors(['test' => 'Este protocolo não está disponível no plano desta organização.']);
        }

        $user = $this->user($request);

        try {
            return $this->redirector->redirect($connection, $request, OidcLoginFlow::MODE_TEST, $user, $user->email);
        } catch (SsoFailure $failure) {
            $this->completer->recordTestFailure($connection, $failure, $user);

            return back()->withErrors(['test' => $failure->userMessage()]);
        }
    }

    public function importMetadata(Request $request, SsoConnection $connection, SamlMetadataImporter $importer): RedirectResponse
    {
        $this->organization();
        abort_unless($connection->isSaml(), 404);

        $data = $request->validate([
            'metadata_url' => ['nullable', 'required_without:metadata_xml', 'string', 'max:1000'],
            'metadata_xml' => ['nullable', 'required_without:metadata_url', 'string', 'max:524288'],
        ]);

        try {
            $url = is_string($data['metadata_url'] ?? null) ? trim($data['metadata_url']) : '';
            $imported = $url !== '' ? $importer->fromUrl($url) : $importer->fromXml((string) $data['metadata_xml']);

            $this->manager->update($connection, [
                'saml_idp_entity_id' => $imported['entity_id'],
                'saml_idp_sso_url' => $imported['sso_url'],
                'saml_idp_certificates' => implode("\n", $imported['certificates']),
                'saml_metadata_url' => $url !== '' ? $url : $connection->saml_metadata_url,
            ], $this->user($request), $this->isOwner());
        } catch (SsoFailure $failure) {
            return back()->withErrors(['metadata' => $failure->userMessage()]);
        }

        return back()->with('success', 'Metadata importado. Teste a conexão antes de ativá-la.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(SsoProtocol $protocol, bool $creating): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'jit_provisioning' => ['sometimes', 'boolean'],
            'jit_role' => ['sometimes', Rule::in([MembershipRole::Member->value, MembershipRole::Admin->value])],
            'two_factor_policy' => ['sometimes', Rule::in([SsoConnection::TWO_FACTOR_KEEP, SsoConnection::TWO_FACTOR_TRUST_IDP])],
        ];

        if ($creating) {
            $rules['protocol'] = ['required', Rule::in([SsoProtocol::Oidc->value, SsoProtocol::Saml->value])];
        } else {
            $rules['enforce'] = ['sometimes', 'boolean'];
        }

        if ($protocol === SsoProtocol::Oidc) {
            return [
                ...$rules,
                'oidc_issuer' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
                'oidc_client_id' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
                'oidc_client_secret' => [$creating ? 'required' : 'nullable', 'string', 'max:2000'],
                'oidc_id_token_alg' => ['sometimes', Rule::in((array) config('assinavelox.sso.oidc_algorithms', []))],
            ];
        }

        return [
            ...$rules,
            'saml_idp_entity_id' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
            'saml_idp_sso_url' => [$creating ? 'required' : 'sometimes', 'string', 'max:1000'],
            'saml_idp_certificates' => [$creating ? 'required' : 'nullable', 'string', 'max:20000'],
            'saml_metadata_url' => ['nullable', 'string', 'max:1000'],
            'saml_allow_idp_initiated' => ['sometimes', 'boolean'],
        ];
    }

    private function organization(): Organization
    {
        $organization = CurrentOrganization::instance()->get();

        abort_if($organization === null || ! SsoFeature::anyFor($organization), 404);
        Gate::authorize('updateSettings', $organization);

        return $organization;
    }

    private function isOwner(): bool
    {
        return CurrentOrganization::instance()->membership()?->role === MembershipRole::Owner;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
