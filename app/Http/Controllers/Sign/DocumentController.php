<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Services\Documents\DocumentStorage;
use Illuminate\Http\Request;
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

        $filename = $this->storage->downloadFilename($context->envelope->title, $context->envelope->display_code, 'pdf');

        return $this->storage->stream($version, $filename, 'inline', 'application/pdf');
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
