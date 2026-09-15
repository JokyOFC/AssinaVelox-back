<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Integrations\Middleware\AllowCloudPickers;
use App\Http\Controllers\Integrations\Middleware\EnsureCloudImportFeature;
use App\Integrations\GoogleDrive\GoogleDriveSource;
use App\Integrations\GoogleDrive\GoogleOAuthClient;
use App\Models\CloudImport;
use App\Models\Envelope;
use App\Models\User;
use App\Services\CloudImport\CloudFileSources;
use App\Services\CloudImport\CloudImporter;
use App\Services\CloudImport\CloudImportRejected;
use App\Services\CloudImport\CloudProvider;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Services\CloudImport\GoogleImportSession;
use App\Services\CloudImport\OAuth\CallbackQuery;
use App\Services\CloudImport\OAuth\OAuthStateStore;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Envelopes\DomainFeatures;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importar da nuvem (Fase 3 §3.9, G-CONN, docs/fase-3/conectores.md §4 e §8).
 *
 * Flag `cloud_import` desligada: 404 em tudo. Autorização: `update` do envelope (quem pode
 * enviar arquivo no wizard pode importar). O arquivo entra pelo mesmo caminho de um upload.
 *
 *  - `show`: página da importação — estado honesto de cada provedor ("aguardando app
 *    registrado pelo proprietário" sem credenciais), CSP relaxada SÓ aqui;
 *  - Google: `googleStart` (state + PKCE, redireciona ao Google) → `googleCallback` (troca o
 *    code, guarda o token cifrado na sessão) → `googleToken` (POST JSON sem cache, só para o
 *    Picker) → `googleStore` (baixa, importa, apaga e revoga o token);
 *  - Dropbox: `dropboxStore` (link direto do Chooser; sem token).
 */
final class CloudImportController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly CloudImporter $importer,
        private readonly OAuthStateStore $states,
        private readonly GoogleImportSession $googleSession,
        private readonly GoogleOAuthClient $google,
        private readonly DocumentIntake $intake,
    ) {}

    public static function middleware(): array
    {
        return [
            EnsureCloudImportFeature::class,
            new Middleware(AllowCloudPickers::class, only: ['show']),
        ];
    }

    public function show(Request $request, Envelope $envelope): Response
    {
        Gate::authorize('update', $envelope);

        /** @var User $user */
        $user = $request->user();
        $google = CloudFileSources::for(CloudProvider::GoogleDrive);
        $dropbox = CloudFileSources::for(CloudProvider::Dropbox);

        return Inertia::render('integrations/cloud-import', [
            'envelope' => [
                'id' => $envelope->ulid,
                'title' => $envelope->title,
                'edit_url' => route('envelopes.edit', $envelope),
            ],
            'editable' => $this->intake->acceptsUpload($envelope),
            'capacity' => $this->capacity($envelope),
            'providers' => [
                'google_drive' => [
                    'available' => $google->isConfigured(),
                    'simulated' => $google->isSimulated(),
                    'missing' => $google->missingConfiguration(),
                    'authorized' => $google->isConfigured() && $this->googleSession->authorized($envelope, $user),
                    'picker' => $google->isConfigured() && ! $google->isSimulated() ? $this->google->pickerConfig() : null,
                ],
                'dropbox' => [
                    'available' => $dropbox->isConfigured(),
                    'simulated' => $dropbox->isSimulated(),
                    'missing' => $dropbox->missingConfiguration(),
                    'app_key' => $dropbox->isConfigured() && ! $dropbox->isSimulated() ? (string) config('services.dropbox.app_key') : null,
                ],
            ],
            'recent' => CloudImport::query()
                ->where('envelope_id', $envelope->getKey())
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(static fn (CloudImport $import): array => [
                    'id' => $import->ulid,
                    'provider' => $import->provider->value,
                    'provider_label' => $import->provider->label(),
                    'name' => $import->original_filename,
                    'status' => $import->status,
                    'code' => $import->rejection_code,
                    // Motivo legível (PT-BR) da recusa; a tela nunca mostra o código cru.
                    'reason' => $import->status === CloudImport::STATUS_REJECTED
                        ? CloudImportRejected::describe($import->rejection_code)
                        : null,
                    'simulated' => $import->simulated,
                    'created_at' => $import->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function googleStart(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        $source = CloudFileSources::for(CloudProvider::GoogleDrive);

        if (! $source->isConfigured() || $source->isSimulated()) {
            return redirect()->route('cloud_import.show', $envelope)
                ->with('error', 'Importação do Google Drive ainda não disponível: aguardando app registrado pelo proprietário.');
        }

        $pending = $this->states->issue('google_drive', [
            'organization' => (int) $envelope->organization_id,
            'user' => (int) $request->user()?->getKey(),
            'envelope' => $envelope->ulid,
        ]);

        return redirect()->away($this->google->authorizationUrl((string) $pending->state, (string) $pending->challenge, route('cloud_import.google.callback')));
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $query = CallbackQuery::take($request, ['state', 'code', 'error']);
        $consumed = $this->states->consume('google_drive', $query['state']);
        $organization = CurrentOrganization::instance()->get();
        /** @var User $user */
        $user = $request->user();

        if ($consumed === null
            || $organization === null
            || (int) $consumed->contextValue('organization') !== (int) $organization->getKey()
            || (int) $consumed->contextValue('user') !== (int) $user->getKey()) {
            return redirect()->route('envelopes.index')
                ->with('error', 'A autorização do Google Drive expirou ou já foi usada. Comece a importação de novo pelo documento.');
        }

        /** @var Envelope|null $envelope */
        $envelope = Envelope::query()->where('ulid', (string) $consumed->contextValue('envelope'))->first();

        if ($envelope === null || Gate::denies('update', $envelope)) {
            return redirect()->route('envelopes.index')->with('error', 'Documento não encontrado.');
        }

        if ($query['error'] !== null) {
            return redirect()->route('cloud_import.show', $envelope)->with('error', 'A autorização foi cancelada no Google. Nada foi importado.');
        }

        $code = $query['code'];
        $verifier = $consumed->verifier();

        if (! is_string($code) || $code === '' || strlen($code) > 2048 || $verifier === null) {
            return redirect()->route('cloud_import.show', $envelope)->with('error', 'Resposta inválida do Google. Tente de novo.');
        }

        try {
            $token = $this->google->exchange($code, $verifier, route('cloud_import.google.callback'));
        } catch (CloudImportRejected $rejected) {
            return redirect()->route('cloud_import.show', $envelope)->with('error', $rejected->userMessage());
        }

        $this->googleSession->store($envelope, $user, $token);

        return redirect()->route('cloud_import.show', $envelope)->with('success', 'Google Drive autorizado. Escolha os arquivos.');
    }

    /**
     * Token do Picker. POST (não GET): nada de pré-carregamento, cache ou histórico. Só dentro
     * da janela da sessão, para o mesmo usuário e o mesmo envelope que autorizaram.
     */
    public function googleToken(Request $request, Envelope $envelope): JsonResponse
    {
        Gate::authorize('update', $envelope);

        /** @var User $user */
        $user = $request->user();
        $token = $this->googleSession->token($envelope, $user);

        if ($token === null) {
            return response()->json(['message' => 'Autorize o Google Drive de novo para escolher os arquivos.'], 409)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'access_token' => $token,
            'expires_in' => $this->googleSession->secondsLeft($envelope, $user),
            ...$this->google->pickerConfig(),
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function googleStore(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        $max = $this->capacity($envelope)['remaining'];
        $data = $request->validate([
            'file_ids' => ['required', 'array', 'min:1', 'max:'.max(1, $max)],
            'file_ids.*' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{10,256}$/'],
        ], [
            'file_ids.max' => 'Escolha no máximo :max arquivo(s) para este documento.',
            'file_ids.*.regex' => 'Arquivo do Google Drive inválido.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $token = $this->googleSession->token($envelope, $user);

        if ($token === null) {
            return redirect()->route('cloud_import.show', $envelope)->with('error', 'A autorização do Google Drive expirou. Autorize de novo e escolha os arquivos.');
        }

        $source = CloudFileSources::for(CloudProvider::GoogleDrive);
        $imported = 0;
        $error = null;

        try {
            foreach (array_values(array_unique($data['file_ids'])) as $id) {
                try {
                    $this->importer->import($envelope, $user, $source, new CloudFileSelection(CloudProvider::GoogleDrive, externalId: $id, accessToken: $token), $request);
                    $imported++;
                } catch (CloudImportRejected $rejected) {
                    $error = $rejected->userMessage();

                    break;
                }
            }
        } finally {
            // "Nenhum token além da importação": sai da sessão e é revogado no Google, deu certo ou não.
            $this->googleSession->forget();

            if (! $source->isSimulated() && $source instanceof GoogleDriveSource) {
                $this->google->revoke($token);
            }
        }

        return $this->finish($envelope, $imported, $error, CloudProvider::GoogleDrive);
    }

    public function dropboxStore(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        $max = $this->capacity($envelope)['remaining'];
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.max(1, $max)],
            'files.*.link' => ['required', 'string', 'max:2048'],
            'files.*.name' => ['required', 'string', 'max:255'],
            'files.*.id' => ['nullable', 'string', 'max:140'],
            'files.*.bytes' => ['nullable', 'integer', 'min:0'],
        ], [
            'files.max' => 'Escolha no máximo :max arquivo(s) para este documento.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $source = CloudFileSources::for(CloudProvider::Dropbox);
        $imported = 0;
        $error = null;

        foreach ($data['files'] as $file) {
            try {
                $this->importer->import($envelope, $user, $source, new CloudFileSelection(
                    CloudProvider::Dropbox,
                    externalId: isset($file['id']) ? (string) $file['id'] : null,
                    name: (string) $file['name'],
                    link: (string) $file['link'],
                    declaredBytes: isset($file['bytes']) ? (int) $file['bytes'] : null,
                ), $request);
                $imported++;
            } catch (CloudImportRejected $rejected) {
                $error = $rejected->userMessage();

                break;
            }
        }

        return $this->finish($envelope, $imported, $error, CloudProvider::Dropbox);
    }

    /**
     * @return array{remaining: int, max_documents: int, replaces: bool, max_bytes: int, max_files: int, extensions: list<string>}
     */
    private function capacity(Envelope $envelope): array
    {
        $organization = $envelope->organization;
        $multi = DomainFeatures::multiDocument($organization);
        $maxDocuments = DomainFeatures::maxDocuments($organization);
        $existing = EnvelopeDocuments::count($envelope);
        $remaining = $multi ? max(0, $maxDocuments - $existing) : 1;

        $extensions = [];

        foreach ((array) config('assinavelox.upload.extensions', []) as $list) {
            foreach ((array) $list as $extension) {
                if (is_string($extension) && $extension !== '') {
                    $extensions[$extension] = true;
                }
            }
        }

        return [
            'remaining' => min($remaining, max(1, (int) config('assinavelox.cloud_import.max_files_per_import', 10))),
            'max_documents' => $maxDocuments,
            'replaces' => ! $multi && $existing > 0,
            'max_bytes' => $this->importer->maxBytes(),
            'max_files' => max(1, (int) config('assinavelox.cloud_import.max_files_per_import', 10)),
            'extensions' => array_keys($extensions),
        ];
    }

    private function finish(Envelope $envelope, int $imported, ?string $error, CloudProvider $provider): RedirectResponse
    {
        if ($error !== null && $imported === 0) {
            return redirect()->route('cloud_import.show', $envelope)->withErrors(['file' => $error]);
        }

        $message = $imported === 1
            ? sprintf('1 arquivo importado do %s. Estamos preparando o documento.', $provider->label())
            : sprintf('%d arquivos importados do %s. Estamos preparando o documento.', $imported, $provider->label());

        $redirect = redirect()->route('envelopes.edit', $envelope)->with('success', $message);

        return $error !== null ? $redirect->with('warning', 'Alguns arquivos não foram importados: '.$error) : $redirect;
    }
}
