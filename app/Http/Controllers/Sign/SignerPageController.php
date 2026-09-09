<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Services\Signing\InvitationOpens;
use App\Services\Signing\SignerPageProps;
use App\Services\Signing\SignerSessions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página pública do signatário (ROUTES §2.18 / arquitetura §4.1).
 *
 * Este GET é **somente leitura do ponto de vista do convite**: não consome o link, não cria
 * aceite, não invalida nada. O único efeito colateral é registrar a primeira abertura
 * (`invitation.opened`, `notified → viewed`) — ver {@see InvitationOpens} para o porquê da
 * distinção entre "abertura detectada" e "leitura".
 *
 * A tela devolvida depende de dois eixos independentes: o estado do **convite** (resolvido
 * pelo middleware, sem olhar o navegador) e a existência de **sessão autenticada** neste
 * navegador. Só a combinação dos dois separa `identify` de `sign`.
 */
class SignerPageController extends Controller
{
    public function __construct(
        private readonly SignerPageProps $props,
        private readonly SignerSessions $sessions,
        private readonly InvitationOpens $opens,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $context = ResolveSignerToken::context($request);

        $this->opens->record($context);

        // O registro da abertura muda o status do destinatário: as props precisam refletir
        // o que acabou de ser gravado, não o que foi lido antes.
        $context = $context->refreshed();

        $session = $context->isActive() ? $this->sessions->current($context, $request) : null;

        return Inertia::render('sign/show', $this->props->build($context, $request, $session));
    }
}
