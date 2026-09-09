<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Envelopes\SyncFieldsRequest;
use App\Models\Envelope;
use App\Services\Envelopes\FieldSync;
use Illuminate\Http\RedirectResponse;

/**
 * Campos de assinatura do envelope — ROUTES §1.2 `envelopes.fields.sync`.
 *
 * A validação geométrica, a rubrica automática em todas as páginas e a origem dos dados
 * de página estão em `App\Services\Envelopes\FieldSync` (docs/campos-e-geometria.md).
 */
class EnvelopeFieldController extends Controller
{
    public function __construct(private readonly FieldSync $fields) {}

    public function sync(SyncFieldsRequest $request, Envelope $envelope): RedirectResponse
    {
        if (! $envelope->status->isDraftLike()) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        $this->fields->handle($envelope, $request->payload());

        return back();
    }
}
