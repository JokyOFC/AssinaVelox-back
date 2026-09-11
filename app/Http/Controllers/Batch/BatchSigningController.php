<?php

namespace App\Http\Controllers\Batch;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sign\DocumentController as SignDocumentController;
use App\Http\Middleware\EnsureSignerVerified;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Batch\AuthorizeBatchItemRequest;
use App\Http\Requests\Sign\VerifyOtpRequest;
use App\Models\Recipient;
use App\Services\Batch\BatchBrowser;
use App\Services\Batch\BatchChallenges;
use App\Services\Batch\BatchItems;
use App\Services\Batch\BatchLinks;
use App\Services\Batch\BatchPageProps;
use App\Services\Batch\Models\BatchSigningSession;
use App\Services\InPerson\ParticipantContexts;
use App\Services\InPerson\PresenceFeatures;
use App\Services\Signing\Exceptions\SigningRejectedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Página pública do lote (`assinar/lote`, docs/fase-2/presencial-e-lote.md §3).
 *
 * - `GET assinar/lote/{token}`: troca o token do e-mail por uma entrada na sessão e
 *   redireciona para `assinar/lote` (o token some da barra de endereços). Token inválido:
 *   404 genérico, igual ao link individual.
 * - `GET assinar/lote`: identificação (código por e-mail), lista de documentos com o estado de
 *   cada um e, com `?item=`, o documento aberto para revisão e autorização.
 * - `POST .../itens/{item}/abrir` e `.../itens/{item}/autorizar`: UM item por vez. Não há rota
 *   que autorize mais de um item.
 */
class BatchSigningController extends Controller
{
    public function __construct(
        private readonly BatchLinks $links,
        private readonly BatchBrowser $browser,
        private readonly BatchChallenges $challenges,
        private readonly BatchItems $items,
        private readonly BatchPageProps $props,
    ) {}

    public function show(Request $request, ?string $token = null): HttpResponse
    {
        if (! PresenceFeatures::global(PresenceFeatures::BATCH_SIGNING)) {
            return Inertia::render('sign-batch/show', $this->props->empty('unavailable'))->toResponse($request);
        }

        if ($token !== null) {
            $batch = $this->links->resolve($token);

            if ($batch === null) {
                return $this->invalid($request);
            }

            $previous = $this->browser->current($request);

            if ($previous !== null && $previous->getKey() !== $batch->getKey()) {
                $this->items->revokeOpenSessions($previous, $request);
                $this->browser->forget($request, null);
            }

            $this->browser->remember($request, $token);

            return redirect()->route('sign.batch.show');
        }

        $batch = $this->browser->current($request);

        if ($batch === null) {
            return $this->browser->hasLink($request)
                ? $this->invalid($request)
                : Inertia::render('sign-batch/show', $this->props->empty('none'))->toResponse($request);
        }

        $item = $request->query('item');

        return Inertia::render('sign-batch/show', $this->props->build($batch, $request, is_string($item) ? $item : null))
            ->toResponse($request);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $batch = $this->batch($request);

        try {
            $this->challenges->send($batch, $request);
        } catch (SigningRejectedException $exception) {
            return $this->home()->withErrors(['otp' => $exception->getMessage()]);
        }

        /** @var Recipient|null $anchor */
        $anchor = Recipient::withoutOrganizationScope()->whereKey($batch->anchor_recipient_id)->first();

        return $this->home()->with('info', sprintf('Enviamos um código para %s.', $anchor->masked_email ?? 'o seu e-mail'));
    }

    public function verifyCode(VerifyOtpRequest $request): RedirectResponse
    {
        $batch = $this->batch($request);

        try {
            $this->challenges->verify($batch, $request, $request->code());
        } catch (SigningRejectedException $exception) {
            return $this->home()->withErrors(['code' => $exception->getMessage()]);
        }

        return $this->home()->with('success', 'Código confirmado. Abra, revise e autorize cada documento separadamente.');
    }

    public function open(Request $request, string $item): RedirectResponse
    {
        $batch = $this->batch($request);

        if (! $this->browser->isAuthenticated($batch, $request)) {
            return $this->expired();
        }

        $row = $this->items->find($batch, strtoupper($item));

        abort_if($row === null, 404);

        try {
            $this->items->open($batch, $row, $request);
        } catch (SigningRejectedException $exception) {
            return $this->home()->withErrors(['item' => $exception->getMessage()]);
        }

        return redirect()->route('sign.batch.show', ['item' => $row->ulid]);
    }

    /**
     * PDF de um item aberto (mesmo `Sign\DocumentController`). 404 seco sem lote autenticado,
     * sem item deste lote ou sem a sessão daquele item neste navegador.
     */
    public function document(Request $request): HttpResponse
    {
        abort_unless(PresenceFeatures::global(PresenceFeatures::BATCH_SIGNING), 404);

        $batch = $this->browser->current($request);

        abort_if($batch === null || ! $this->browser->isAuthenticated($batch, $request), 404, 'Documento indisponível.');

        $ulid = $request->query('item');
        $row = is_string($ulid) ? $this->items->find($batch, strtoupper($ulid)) : null;

        abort_if($row === null, 404, 'Documento indisponível.');

        $described = $this->items->describe($row);
        $context = $described['context'];

        abort_if(! $described['authorizable'] || $context === null, 404, 'Documento indisponível.');

        $session = $this->items->session($row, $context, $request);

        abort_if($session === null, 404, 'Documento indisponível.');

        $request->attributes->set(ResolveSignerToken::ATTRIBUTE, $context);
        $request->attributes->set(EnsureSignerVerified::ATTRIBUTE, $session);

        return app(SignDocumentController::class)->show($request, ParticipantContexts::NO_TOKEN);
    }

    public function authorizeItem(AuthorizeBatchItemRequest $request, string $item): RedirectResponse
    {
        $batch = $this->batch($request);

        if (! $this->browser->isAuthenticated($batch, $request)) {
            return $this->expired();
        }

        $row = $this->items->find($batch, strtoupper($item));

        abort_if($row === null, 404);

        try {
            $this->items->authorize($batch, $row, $request, $request->payload());
        } catch (SigningRejectedException $exception) {
            $field = $exception->context['field'] ?? null;
            $key = is_string($field) && $field !== '' ? 'fields.'.$field : 'signature';

            // Item que deixou de ser autorizável volta para a lista; os demais seguem abertos.
            $stillOpen = $this->items->describe($row)['authorizable'];

            return redirect()
                ->route('sign.batch.show', $stillOpen ? ['item' => $row->ulid] : [])
                ->withErrors($stillOpen ? [$key => $exception->getMessage()] : ['item' => $exception->getMessage()]);
        }

        $title = $row->envelope()->value('title');

        return $this->home()->with('success', sprintf('Aceite registrado para "%s". Os demais documentos continuam pendentes até você autorizar cada um.', $title));
    }

    public function leave(Request $request): RedirectResponse
    {
        $batch = $this->browser->current($request);

        if ($batch !== null) {
            $this->items->revokeOpenSessions($batch, $request);
        }

        $this->browser->forget($request, $batch);

        return $this->home()->with('info', 'Você saiu da lista neste navegador.');
    }

    // -- Internos ----------------------------------------------------------------------

    private function home(): RedirectResponse
    {
        return redirect()->route('sign.batch.show');
    }

    private function expired(): RedirectResponse
    {
        return $this->home()->with('error', 'Sua sessão da lista expirou. Confirme o código enviado por e-mail para continuar.');
    }

    private function batch(Request $request): BatchSigningSession
    {
        abort_unless(PresenceFeatures::global(PresenceFeatures::BATCH_SIGNING), 404);

        $batch = $this->browser->current($request);

        abort_if($batch === null, 404, 'Este link de lote não existe ou foi substituído.');

        return $batch;
    }

    private function invalid(Request $request): HttpResponse
    {
        return Inertia::render('sign-batch/show', $this->props->empty('invalid'))
            ->toResponse($request)
            ->setStatusCode(404);
    }
}
