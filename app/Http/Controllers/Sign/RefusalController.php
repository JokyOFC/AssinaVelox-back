<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Sign\StoreRefusalRequest;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\RecordRefusal;
use Illuminate\Http\RedirectResponse;

/**
 * Recusa do signatário (ROUTES §1.3 `sign.refuse`, arquitetura §4.6).
 *
 * Exige sessão autenticada, como o aceite: recusar em nome de outra pessoa é tão grave
 * quanto assinar por ela — encerra o documento e cancela os demais participantes. Na etapa
 * `identify`, a única "recusa" possível é fechar a página, e o aviso de privacidade diz isso.
 */
class RefusalController extends Controller
{
    public function __construct(private readonly RecordRefusal $refusals) {}

    public function store(StoreRefusalRequest $request, string $token): RedirectResponse
    {
        $context = ResolveSignerToken::context($request);

        try {
            $this->refusals->handle($context, $request->reason());
        } catch (SigningRejectedException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('info', 'Recusa registrada. O remetente foi avisado.');
    }
}
