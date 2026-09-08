<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cópia para o signatário após a conclusão (ROUTES §1.3 sign.download).
 * // TODO(Wave C): link de download próprio (purpose=download, com expiração) + stream do PDF final.
 */
class DownloadController extends Controller
{
    public function show(Request $request, string $token, string $type): Response
    {
        abort_unless(in_array($type, ['signed', 'evidence'], true), 404);

        abort(404, 'Arquivo indisponível.');
    }
}
