<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Sign\VerifyOtpRequest;
use App\Http\Requests\Sign\VerifyPinRequest;
use App\Services\Signing\Challenges;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\Channels\SignerAuthProps;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerDownloadGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Código de confirmação (ROUTES §1.3 `sign.otp.*`, arquitetura §4.2/§4.3) — por e-mail, SMS
 * ou WhatsApp, conforme o método escolhido pelo remetente (Fase 2 §2.9) — e PIN do remetente
 * (`sign.pin.verify`).
 *
 * As ações respondem com redirect para `sign.show`, que recalcula as props: assim a tela
 * reflete sempre o estado do servidor (código vivo, tentativas restantes, quando pode
 * reenviar, etapa do PIN) em vez de um estado paralelo mantido no navegador.
 *
 * Nenhuma resposta — nem de sucesso, nem de erro — contém o código ou o PIN.
 */
class OtpController extends Controller
{
    public function __construct(
        private readonly Challenges $challenges,
        private readonly SignerDownloadGrants $grants,
        private readonly SenderPins $pins,
    ) {}

    public function send(Request $request, string $token): RedirectResponse
    {
        $context = ResolveSignerToken::context($request);

        try {
            $this->challenges->send($context, $request);
        } catch (SigningRejectedException $exception) {
            return back()->withErrors(['otp' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('info', sprintf('Enviamos um código para %s.', SignerAuthProps::destination($context)));
    }

    public function verify(VerifyOtpRequest $request, string $token): RedirectResponse
    {
        $context = ResolveSignerToken::context($request);

        try {
            $this->challenges->verify($context, $request, $request->code());
        } catch (SigningRejectedException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        // Sessão nova: reemite o id de sessão para fechar fixação de sessão em um fluxo que
        // troca o nível de privilégio do navegador (anônimo → identificado). Os dados da
        // sessão (inclusive o portão do PIN) são preservados.
        $request->session()->migrate(true);

        // Fase 2 §2.9: com PIN do remetente, o código só abre a etapa do PIN. Nada de janela
        // de download antes de o PIN conferir.
        if ($this->pins->pendingGate($context, $request) !== null) {
            return redirect()
                ->route('sign.show', ['token' => $token])
                ->with('info', 'Código confirmado. Agora informe o PIN que quem enviou o documento combinou com você.');
        }

        // Confirmou o código: abre também a janela de download (arquitetura §4.7). É o que
        // permite a quem já assinou voltar ao próprio comprovante sem que a posse do link
        // sozinha autorize qualquer coisa.
        $this->grants->issue($context, $request);

        return redirect()
            ->route('sign.show', ['token' => $token])
            // O código prova a posse do canal (e-mail, SMS ou WhatsApp), não a identidade
            // (arquitetura §2, T1): a mensagem descreve o meio.
            ->with('success', 'Código confirmado. Revise o documento e registre seu aceite.');
    }

    public function verifyPin(VerifyPinRequest $request, string $token): RedirectResponse
    {
        $context = ResolveSignerToken::context($request);

        try {
            $this->pins->verify($context, $request, $request->pin());
        } catch (SigningRejectedException $exception) {
            return back()->withErrors(['pin' => $exception->getMessage()]);
        }

        $request->session()->migrate(true);

        $this->grants->issue($context, $request);

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('success', 'PIN confirmado. Revise o documento e registre seu aceite.');
    }
}
