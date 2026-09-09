<?php

namespace App\Http\Controllers\Sign;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSignerVerified;
use App\Http\Middleware\ResolveSignerToken;
use App\Models\DocumentVersion;
use App\Models\SigningSession;
use App\Services\Documents\DocumentStorage;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transmissão do PDF para o signatário autenticado (ROUTES §1.3 `sign.document`).
 *
 * O arquivo vive em disco privado e **não existe URL pública nem assinada** para ele
 * (RECONCILIACAO Q23): cada byte passa por este controller, que só é alcançado depois de
 * `ResolveSignerToken` (o link vale) e `EnsureSignerVerified` (o código foi confirmado, a
 * sessão é desta pessoa e aponta para a versão enviada).
 *
 * A versão transmitida é sempre `envelopes.sent_document_version_id` — a que foi congelada
 * no envio e cujo SHA-256 a declaração de aceite referencia. Nunca a "versão atual" do
 * documento: se ela mudasse, a pessoa assinaria um texto e a evidência apontaria outro.
 *
 * Cabeçalhos: `inline` para o PDF.js, `no-store` (documento privado não fica em cache de
 * navegador nem de proxy) e `nosniff`. `X-Robots-Tag: noindex` e `Referrer-Policy:
 * no-referrer` são aplicados a todo `/assinar/*` pelo middleware `SecurityHeaders`.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function show(Request $request, string $token): Response
    {
        $context = ResolveSignerToken::context($request);

        $version = $context->sentVersion();

        abort_if($version === null, 404, 'Documento indisponível.');
        abort_unless($this->storage->exists($version), 404, 'Documento indisponível.');

        $this->markPresented($request, $context, $version);

        $filename = $this->storage->downloadFilename($context->envelope->title, $context->envelope->display_code, 'pdf');

        return $this->storage->stream($version, $filename, 'inline', 'application/pdf');
    }

    /**
     * Registra que os bytes saíram do servidor PARA ESTA sessão.
     *
     * A declaração que o signatário assina afirma "Li integralmente o documento […], cujo
     * conteúdo apresentado nesta tela corresponde ao resumo SHA-256 …". Sem este registro a
     * trilha ia de `invitation.opened` — que a própria página de evidências rotula
     * "registra o acesso ao link, não comprova leitura" — direto para `acceptance.recorded`,
     * e nada no dossiê sustentava a palavra "apresentado". `RecordAcceptance` passa a exigir
     * a marca antes de gravar o aceite.
     *
     * O que fica registrado é a ENTREGA do arquivo, não a leitura — e a interface e a página
     * de evidências dizem isso com todas as letras. Uma marca por sessão: recarregar a
     * página não polui a trilha.
     */
    private function markPresented(Request $request, SignerContext $context, DocumentVersion $version): void
    {
        /** @var SigningSession|null $session */
        $session = $request->attributes->get(EnsureSignerVerified::ATTRIBUTE);

        if (! $session instanceof SigningSession || $session->document_presented_at !== null) {
            return;
        }

        $now = Carbon::now();

        $session->forceFill(['document_presented_at' => $now])->save();

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::DocumentPresented, [
            'document_version_ulid' => $version->ulid,
            'sha256' => $version->sha256,
            'session' => $session->ulid,
        ]);
    }

    /**
     * Miniatura de página.
     *
     * Descontinuada por decisão de arquitetura, a mesma do lado do app
     * (docs/preparacao-documental.md): gerar PNG no servidor exigiria um rasterizador
     * (Ghostscript, poppler, pdfium) que o projeto decidiu não ter — o `pdftool` não
     * rasteriza — e custaria um processo e um arquivo por página a cada visita. O rail de
     * páginas é desenhado no navegador com PDF.js, que já baixa o PDF uma única vez.
     *
     * A rota continua registrada e responde 404 com mensagem explícita para que nada dependa
     * dela em silêncio; `document.page_thumb_url_template` vem `null` nas props.
     */
    public function page(Request $request, string $token, int $page): Response
    {
        ResolveSignerToken::context($request);

        abort(404, 'Miniaturas de página não são geradas no servidor: o documento é exibido no navegador.');
    }
}
