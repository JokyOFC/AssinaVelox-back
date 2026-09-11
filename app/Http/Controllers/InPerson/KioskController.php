<?php

namespace App\Http\Controllers\InPerson;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sign\CaptureController as SignCaptureController;
use App\Http\Controllers\Sign\DocumentController as SignDocumentController;
use App\Http\Middleware\EnsureSignerVerified;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\InPerson\ParticipantAcceptanceRequest;
use App\Http\Requests\InPerson\SelectParticipantRequest;
use App\Http\Requests\Sign\VerifyOtpRequest;
use App\Http\Requests\Sign\VerifyPinRequest;
use App\Services\InPerson\InPersonKioskProps;
use App\Services\InPerson\InPersonSessions;
use App\Services\InPerson\InPersonTurns;
use App\Services\InPerson\Models\InPersonSession;
use App\Services\InPerson\Models\InPersonTurn;
use App\Services\InPerson\ParticipantContexts;
use App\Services\InPerson\PresenceFeatures;
use App\Services\Signing\Challenges;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\Channels\SignerAuthProps;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\RecordAcceptance;
use App\Services\Signing\SignerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * O DISPOSITIVO presencial (docs/fase-2/presencial-e-lote.md §2.3–§2.4). Rotas públicas
 * `presencial/*`, sem conta: o que autoriza é o segredo do dispositivo na sessão Laravel
 * (posto pelo anfitrião ao abrir a sessão) e, para tudo que toca o documento, a sessão de
 * assinatura do PRÓPRIO participante da vez, criada pelo código dele.
 *
 * Cada ação delega ao serviço do fluxo individual, com o contexto do participante da vez:
 * `Challenges` (código pelo canal dele), `SenderPins` (PIN, se houver), `RecordAcceptance`
 * (aceite sob lock), `Sign\DocumentController` (entrega do PDF e marca de apresentação) e
 * `Sign\CaptureController` (foto, se exigida e com a flag ligada). Nada disso muda de
 * comportamento: muda só a porta de entrada.
 */
class KioskController extends Controller
{
    public function __construct(
        private readonly InPersonSessions $sessions,
        private readonly InPersonTurns $turns,
        private readonly InPersonKioskProps $props,
        private readonly Challenges $challenges,
        private readonly SenderPins $pins,
        private readonly RecordAcceptance $acceptances,
    ) {}

    public function show(Request $request): Response
    {
        if (! PresenceFeatures::global(PresenceFeatures::IN_PERSON)) {
            return Inertia::render('in-person/kiosk', $this->props->empty($request, 'unavailable'));
        }

        $session = $this->sessions->current($request);

        if ($session === null) {
            return Inertia::render('in-person/kiosk', $this->props->empty($request, 'none'));
        }

        return Inertia::render('in-person/kiosk', $this->props->build($session, $request));
    }

    public function select(SelectParticipantRequest $request): RedirectResponse
    {
        $session = $this->kiosk($request);

        try {
            $this->turns->begin($session, $request->recipientUlid(), $request);
        } catch (SigningRejectedException $exception) {
            abort_if($exception->status === 404, 404);

            return $this->home()->withErrors(['participant' => $exception->getMessage()]);
        }

        return $this->home();
    }

    public function sendCode(Request $request): RedirectResponse
    {
        try {
            [, , $context] = $this->turn($request);
            $this->challenges->send($context, $request);
        } catch (SigningRejectedException $exception) {
            return $this->home()->withErrors(['otp' => $exception->getMessage()]);
        }

        return $this->home()->with('info', sprintf('Enviamos um código para %s.', SignerAuthProps::destination($context)));
    }

    public function verifyCode(VerifyOtpRequest $request): RedirectResponse
    {
        try {
            [, $turn, $context] = $this->turn($request);
            $this->challenges->verify($context, $request, $request->code());
        } catch (SigningRejectedException $exception) {
            return $this->home()->withErrors(['code' => $exception->getMessage()]);
        }

        $request->session()->migrate(true);

        if ($this->pins->pendingGate($context, $request) !== null) {
            return $this->home()->with('info', 'Código confirmado. Agora informe o PIN que quem enviou o documento combinou com você.');
        }

        $signing = $this->turns->signingSession($turn, $context, $request);

        if ($signing !== null) {
            $this->turns->markAuthenticated($turn, $signing);
        }

        return $this->home()->with('success', 'Código confirmado. Revise o documento e registre seu aceite.');
    }

    public function verifyPin(VerifyPinRequest $request): RedirectResponse
    {
        try {
            [, $turn, $context] = $this->turn($request);
            $signing = $this->pins->verify($context, $request, $request->pin());
        } catch (SigningRejectedException $exception) {
            return $this->home()->withErrors(['pin' => $exception->getMessage()]);
        }

        $request->session()->migrate(true);
        $this->turns->markAuthenticated($turn, $signing);

        return $this->home()->with('success', 'PIN confirmado. Revise o documento e registre seu aceite.');
    }

    /**
     * PDF do participante da vez (mesmo `Sign\DocumentController`: versão congelada, marca de
     * apresentação por sessão e documento, `no-store`). 404 seco sem vez ou sem sessão.
     */
    public function document(Request $request): HttpResponse
    {
        try {
            [, $turn, $context] = $this->turn($request);
        } catch (SigningRejectedException) {
            abort(404, 'Documento indisponível.');
        }

        $signing = $this->turns->signingSession($turn, $context, $request);

        abort_if($signing === null, 404, 'Documento indisponível.');

        $this->bind($request, $context, $signing);

        return app(SignDocumentController::class)->show($request, ParticipantContexts::NO_TOKEN);
    }

    /**
     * Foto da captura simples (C-ID), quando exigida e com a flag `identity_capture`.
     */
    public function capture(Request $request, string $kind): JsonResponse|RedirectResponse
    {
        try {
            [, $turn, $context] = $this->turn($request);
        } catch (SigningRejectedException) {
            abort(404);
        }

        $signing = $this->turns->signingSession($turn, $context, $request);

        abort_if($signing === null, 404);

        $this->bind($request, $context, $signing);
        // Resposta sempre JSON: o redirect do controller original aponta para a página do link.
        $request->headers->set('Accept', 'application/json');

        return app(SignCaptureController::class)->store($request, ParticipantContexts::NO_TOKEN, $kind);
    }

    public function accept(ParticipantAcceptanceRequest $request): RedirectResponse
    {
        try {
            [$session, $turn, $context] = $this->turn($request);
        } catch (SigningRejectedException $exception) {
            return $this->home()->withErrors(['signature' => $exception->getMessage()]);
        }

        $signing = $this->turns->signingSession($turn, $context, $request);

        if ($signing === null) {
            return $this->home()->withErrors(['signature' => 'Confirme o código antes de registrar o aceite.']);
        }

        try {
            $acceptance = $this->acceptances->handle($context, $signing, $request, $request->payload());
        } catch (SigningRejectedException $exception) {
            $field = $exception->context['field'] ?? null;

            return $this->home()->withErrors([
                is_string($field) && $field !== '' ? 'fields.'.$field : 'signature' => $exception->getMessage(),
            ]);
        }

        $this->turns->finish($turn, $session, $acceptance, $context, $request);
        $request->session()->flash('in_person.done', true);

        return $this->home()->with('success', 'Aceite registrado. A tela foi bloqueada para o próximo participante.');
    }

    public function lock(Request $request): RedirectResponse
    {
        $session = $this->kiosk($request);

        $this->turns->closeActive($session, InPersonTurn::CLOSE_LOCKED, $request);

        return $this->home()->with('info', 'Tela bloqueada. Nenhum dado do participante anterior fica disponível.');
    }

    public function end(Request $request): RedirectResponse
    {
        abort_unless(PresenceFeatures::global(PresenceFeatures::IN_PERSON), 404);

        $session = $this->sessions->current($request, touch: false);

        if ($session !== null) {
            $this->sessions->end($session, InPersonSession::END_DEVICE, null, $request);
        }

        return $this->home()->with('info', 'Sessão presencial encerrada neste dispositivo.');
    }

    // -- Internos ----------------------------------------------------------------------

    private function home(): RedirectResponse
    {
        return redirect()->route('in_person.kiosk.show');
    }

    private function kiosk(Request $request): InPersonSession
    {
        abort_unless(PresenceFeatures::global(PresenceFeatures::IN_PERSON), 404);

        $session = $this->sessions->current($request);

        abort_if($session === null, 404, 'Nenhuma sessão presencial ativa neste dispositivo.');

        return $session;
    }

    /**
     * @return array{0: InPersonSession, 1: InPersonTurn, 2: SignerContext}
     *
     * @throws SigningRejectedException
     */
    private function turn(Request $request): array
    {
        $session = $this->kiosk($request);
        $turn = $this->turns->current($session, $request);

        if ($turn === null) {
            throw SigningRejectedException::conflict('no_turn', 'A tela está bloqueada. Escolha na fila quem vai usar o dispositivo.');
        }

        $context = $this->turns->context($turn);

        if ($context === null || ! $context->isActive() || $context->action() === null) {
            $this->turns->close($turn, InPersonTurn::CLOSE_NOT_SIGNABLE, $request);

            throw SigningRejectedException::conflict('participant_unavailable', 'Este documento não está mais disponível para este participante.');
        }

        return [$session, $turn, $context];
    }

    /**
     * Entrega aos controllers do fluxo individual o contexto e a sessão que eles esperam dos
     * middlewares `signer`/`signer.verified`.
     */
    private function bind(Request $request, SignerContext $context, mixed $signing): void
    {
        $request->attributes->set(ResolveSignerToken::ATTRIBUTE, $context);
        $request->attributes->set(EnsureSignerVerified::ATTRIBUTE, $signing);
    }
}
