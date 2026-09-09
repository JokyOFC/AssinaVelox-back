<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Sign\VerifyOtpRequest;
use App\Services\Signing\Challenges;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerDownloadGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Código por e-mail (ROUTES §1.3 `sign.otp.*`, arquitetura §4.2/§4.3).
 *
 * As duas ações respondem com redirect para `sign.show`, que recalcula as props: assim a
 * tela reflete sempre o estado do servidor (código vivo, tentativas restantes, quando pode
 * reenviar) em vez de um estado paralelo mantido no navegador.
 *
 * Nenhuma resposta — nem de sucesso, nem de erro — contém o código.
 */
class OtpController extends Controller
{
    public function __construct(
        private readonly Challenges $challenges,
        private readonly SignerDownloadGrants $grants,
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
            ->with('info', sprintf('Enviamos um código para %s.', $context->recipient->masked_email));
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
        // troca o nível de privilégio do navegador (anônimo → identificado).
        $request->session()->migrate(true);

        // Confirmou o código: abre também a janela de download (arquitetura §4.7). É o que
        // permite a quem já assinou voltar ao próprio comprovante sem que a posse do link
        // sozinha autorize qualquer coisa.
        $this->grants->issue($context, $request);

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('success', 'Identidade confirmada. Revise o documento e registre seu aceite.');
    }
}
