<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Enviar para assinatura — ROUTES §1.2 `envelopes.send`.
 *
 * O controller é fino de propósito: autoriza, delega ao serviço e traduz as duas exceções
 * de domínio em flash PT-BR. Toda a regra (lock, congelamento da versão, código de
 * verificação, prazo, consumo do plano, convites) está em
 * `App\Services\Envelopes\Sending\SendEnvelope`.
 *
 * Sucesso → redireciona para `envelopes.show?sent=1`, que o detalhe usa para mostrar a
 * confirmação "Enviado para assinatura" (ROUTES §2.6, DESIGN §6.5).
 */
class EnvelopeSendController extends Controller
{
    public function __construct(private readonly SendEnvelope $sender) {}

    public function store(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('send', $envelope);

        try {
            $result = $this->sender->handle($envelope);
        } catch (SendingBlockedException $exception) {
            // Plano inadimplente ou cota esgotada: o texto já vem pronto para o usuário.
            return back()->with('error', $exception->getMessage());
        } catch (SendingException $exception) {
            return back()->with($exception->errorCode === 'already_sent' ? 'info' : 'error', $exception->getMessage());
        }

        $count = $result['invitations'];

        // `?sent=1` na URL, e não flash de sessão: `EnvelopeController@show` lê `sent` da
        // ENTRADA da requisição (é o contrato de ROUTES §2.6, "redirect
        // envelopes.show?sent=1"). Com o flash, a prop chegava sempre `false` e a tela
        // "Enviado para assinatura" de DESIGN §6.5 era código morto. O parâmetro na URL
        // ainda deixa a confirmação recarregável e compartilhável.
        return redirect()
            ->route('envelopes.show', ['envelope' => $result['envelope'], 'sent' => 1])
            ->with('success', $count === 1
                ? 'Documento enviado. 1 convite foi despachado.'
                : "Documento enviado. {$count} convites foram despachados.");
    }
}
