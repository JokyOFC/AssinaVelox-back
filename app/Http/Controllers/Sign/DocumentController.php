<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF e miniaturas para o signatário autenticado (ROUTES §1.3 sign.document / sign.page).
 * // TODO(Wave B): exigir SigningSession autenticada; stream da versão enviada (sent_document_version_id).
 */
class DocumentController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        abort(404, 'Documento indisponível.');
    }

    public function page(Request $request, string $token, int $page): Response
    {
        abort(404, 'Miniatura indisponível.');
    }
}
