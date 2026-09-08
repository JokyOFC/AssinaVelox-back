<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Services\Organizations\EnvelopeVisibility;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ações em lote (ROUTES §2.5 envelopes.bulk): action ∈ move | resend | cancel.
 * `move` e `cancel` funcionam sobre os envelopes visíveis/autorizados; `resend` // TODO(Wave B).
 */
class EnvelopeBulkController extends Controller
{
    public function store(Request $request, string $action): RedirectResponse
    {
        abort_unless(in_array($action, ['move', 'resend', 'cancel'], true), 404);

        $membership = CurrentOrganization::instance()->membership();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'size:26'],
            'folder_id' => [Rule::requiredIf($action === 'move'), 'nullable', 'string', 'size:26', Rule::exists('folders', 'ulid')->where('organization_id', $membership->organization_id)],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['ids' => 'documentos', 'folder_id' => 'pasta', 'reason' => 'motivo']);

        $envelopes = EnvelopeVisibility::envelopes($membership)->whereIn('ulid', $validated['ids'])->get();
        $user = $request->user();
        $done = 0;
        $skipped = 0;

        foreach ($envelopes as $envelope) {
            switch ($action) {
                case 'move':
                    if ($user->can('move', $envelope)) {
                        $folderId = ! empty($validated['folder_id']) ? Folder::query()->where('ulid', $validated['folder_id'])->value('id') : null;
                        $envelope->forceFill(['folder_id' => $folderId])->save();
                        $done++;
                    } else {
                        $skipped++;
                    }
                    break;
                case 'cancel':
                    if ($user->can('cancel', $envelope) && $envelope->status === EnvelopeStatus::InProgress) {
                        $envelope->transitionTo(EnvelopeStatus::Canceled);
                        $settings = $envelope->settings ?? [];
                        $settings['cancel_reason'] = $validated['reason'] ?? null;
                        $envelope->settings = $settings;
                        $envelope->save();
                        $done++;
                    } else {
                        $skipped++;
                    }
                    break;
                default:
                    // TODO(Wave B): reenvio em lote dos convites pendentes.
                    $skipped++;
            }
        }

        $message = match ($action) {
            'move' => "{$done} documento(s) movido(s).",
            'cancel' => "{$done} documento(s) cancelado(s).",
            default => 'Reenvio em lote estará disponível em breve (Wave B).',
        };

        if ($skipped > 0) {
            $message .= " {$skipped} ignorado(s).";
        }

        return back()->with($done > 0 ? 'success' : 'info', $message);
    }
}
