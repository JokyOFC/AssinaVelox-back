<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSignerVerified;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Sign\StoreAcceptanceRequest;
use App\Models\SigningSession;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\RecordAcceptance;
use App\Services\Signing\SignerDownloadGrants;
use Illuminate\Http\RedirectResponse;

/**
 * Aceite eletrônico (ROUTES §1.3 `sign.complete`, arquitetura §4.5).
 *
 * O controller é fino de propósito: ele exige a sessão (middleware), passa o payload já
 * validado na forma para {@see RecordAcceptance} e traduz a recusa prevista em mensagem de
 * tela. Toda a decisão — revalidação sob lock, normalização da imagem, carimbo da data,
 * snapshot, avanço da ordem, gancho de finalização — mora no serviço.
 *
 * Um aceite recusado volta com `errors` e a pessoa continua na mesma tela; um aceite gravado
 * redireciona para `sign.show`, que agora responde `completed` ou
 * `already_signed_pending_others` com o comprovante.
 */
class SignatureController extends Controller
{
    public function __construct(
        private readonly RecordAcceptance $acceptances,
        private readonly SignerDownloadGrants $grants,
    ) {}

    public function store(StoreAcceptanceRequest $request, string $token): RedirectResponse
    {
        $context = ResolveSignerToken::context($request);

        /** @var SigningSession $session */
        $session = $request->attributes->get(EnsureSignerVerified::ATTRIBUTE);

        try {
            $this->acceptances->handle($context, $session, $request, $request->payload());
        } catch (SigningRejectedException $exception) {
            return back()->withErrors([$this->errorKey($exception) => $exception->getMessage()]);
        }

        // O aceite consumiu a sessão de assinatura. A janela de download (arquitetura
        // §4.7) é o que dá a esta pessoa, NESTE navegador, acesso ao próprio comprovante
        // nos minutos seguintes — sem transformar a posse da URL do convite em
        // autorização permanente.
        $this->grants->issue($context, $request);

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('success', 'Aceite registrado.');
    }

    /**
     * Erros de campo aparecem junto ao campo; os demais, no topo do formulário.
     */
    private function errorKey(SigningRejectedException $exception): string
    {
        $field = $exception->context['field'] ?? null;

        return is_string($field) && $field !== '' ? 'fields.'.$field : 'signature';
    }
}
