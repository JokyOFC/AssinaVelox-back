<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\ResendInvitations;
use App\Services\Organizations\EnvelopeVisibility;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Ações em lote (ROUTES §2.5 `envelopes.bulk`): action ∈ move | resend | cancel.
 *
 * A autorização é POR ITEM (policy do envelope) além do escopo de visibilidade: um
 * `member` que selecione um documento de colega simplesmente não o vê na consulta, e um
 * envelope visível mas não gerenciável é contado em "ignorados". `resend` reaproveita o
 * mesmo serviço do botão "Lembrar pendentes", com os mesmos limites por destinatário.
 */
class EnvelopeBulkController extends Controller
{
    public function __construct(
        private readonly CancelEnvelope $cancellation,
        private readonly ResendInvitations $resends,
    ) {}

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

        $folder = ! empty($validated['folder_id'])
            ? Folder::query()->where('ulid', $validated['folder_id'])->first()
            : null;

        $correlationId = (string) Str::ulid();
        $done = 0;
        $skipped = count($validated['ids']) - $envelopes->count();

        foreach ($envelopes as $envelope) {
            $applied = match ($action) {
                'move' => $this->move($envelope, $folder, $user, $correlationId),
                'cancel' => $this->cancel($envelope, $validated['reason'] ?? null, $user),
                default => $this->resend($envelope, $user),
            };

            $applied ? $done++ : $skipped++;
        }

        $message = match ($action) {
            'move' => $done.' documento(s) movido(s)'.($folder ? ' para '.$folder->name : ' para "Todos"').'.',
            'cancel' => $done.' documento(s) cancelado(s).',
            default => $done.' documento(s) com convites reenviados.',
        };

        if ($skipped > 0) {
            $message .= " {$skipped} ignorado(s).";
        }

        return back()->with($done > 0 ? 'success' : 'info', $message);
    }

    private function move(Envelope $envelope, ?Folder $folder, ?User $user, string $correlationId): bool
    {
        if ($user?->can('move', $envelope) !== true) {
            return false;
        }

        $envelope->forceFill(['folder_id' => $folder?->getKey()])->save();

        EnvelopeAudit::record($envelope, AuditEventType::EnvelopeMoved, [
            'folder' => $folder?->ulid,
            'bulk' => true,
        ], null, $correlationId);

        return true;
    }

    /**
     * Cancelamento em lote: mesma rotina do botão do detalhe (revoga links, avisa quem foi
     * convidado e libera o consumo do plano se ninguém assinou).
     */
    private function cancel(Envelope $envelope, ?string $reason, ?User $user): bool
    {
        if ($user?->can('cancel', $envelope) !== true || $envelope->status !== EnvelopeStatus::InProgress) {
            return false;
        }

        return $this->cancellation->handle($envelope, $reason)['canceled'];
    }

    /**
     * Reenvio em lote: só `in_progress`, e cada destinatário continua sujeito ao intervalo
     * de 10 minutos e ao máximo de reenvios.
     */
    private function resend(Envelope $envelope, ?User $user): bool
    {
        if ($user?->can('update', $envelope) !== true || $envelope->status !== EnvelopeStatus::InProgress) {
            return false;
        }

        try {
            return $this->resends->all($envelope)['sent'] > 0;
        } catch (SendingException) {
            return false;
        }
    }
}
