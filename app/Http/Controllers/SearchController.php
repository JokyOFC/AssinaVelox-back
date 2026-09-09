<?php

namespace App\Http\Controllers;

use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Organizations\EnvelopeVisibility;
use App\Services\Verification\SignatureNarrative;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca global ⌘K (ROUTES §1.2 search.index): até 10 envelopes + 5 signatários visíveis ao
 * usuário (RECONCILIACAO Q7). JSON: { envelopes: [...], recipients: [...] }.
 *
 * O formato de cada item é o que `resources/js/components/command-search.tsx` consome:
 * documentos precisam de `signed_count` e `folder` (cor do badge, DESIGN §5.1) e signatários
 * de `envelope_id`/`envelope_title` (Enter abre `envelopes.show`, ROUTES §5.3).
 */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $membership = CurrentOrganization::instance()->membership();

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['q' => $q, 'envelopes' => [], 'recipients' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $numeric = ltrim(preg_replace('/^av-?/i', '', $q) ?? '', '0');

        $envelopes = EnvelopeVisibility::envelopes($membership)
            ->with(['recipients', 'folder', 'verificationRecord'])
            ->where(function ($query) use ($like, $numeric): void {
                $query->where('title', 'like', $like)
                    ->orWhere('verification_code', 'like', $like);

                if ($numeric !== '' && ctype_digit($numeric)) {
                    $query->orWhere('number', (int) $numeric);
                }
            })
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(function (Envelope $envelope): array {
                $signedCount = $envelope->recipients->where('status', RecipientStatus::Signed)->count();

                return [
                    'id' => $envelope->ulid,
                    'display_code' => $envelope->display_code,
                    'title' => $envelope->title,
                    'status' => $envelope->status->value,
                    // Concluído sem assinatura criptográfica não é "Assinado"
                    // ({@see SignatureNarrative::completedLabel()}).
                    'status_label' => $envelope->status === EnvelopeStatus::Completed
                        ? SignatureNarrative::completedLabel($envelope->verificationRecord)
                        : $envelope->status->labelWithProgress($signedCount),
                    'signed_count' => $signedCount,
                    'recipients_count' => $envelope->recipients->count(),
                    'folder' => $envelope->folder?->name,
                    'url' => route('envelopes.show', $envelope),
                ];
            })
            ->values();

        $recipients = EnvelopeVisibility::recipients($membership)
            ->with('envelope')
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)->orWhere('email', 'like', $like);
            })
            ->latest('updated_at')
            ->limit(5)
            ->get()
            // Defesa em profundidade: sem envelope visível o item não tem para onde navegar.
            ->filter(fn (Recipient $recipient): bool => $recipient->envelope !== null)
            ->map(fn (Recipient $recipient): array => [
                'id' => $recipient->ulid,
                'name' => $recipient->name,
                'initials' => $recipient->initials,
                'email' => $recipient->email,
                'status' => $recipient->status->value,
                'status_label' => $recipient->status->label(),
                'envelope_id' => $recipient->envelope->ulid,
                'envelope_title' => $recipient->envelope->title,
                'envelope' => [
                    'id' => $recipient->envelope->ulid,
                    'display_code' => $recipient->envelope->display_code,
                    'title' => $recipient->envelope->title,
                ],
                'url' => route('envelopes.show', ['envelope' => $recipient->envelope->ulid, 'tab' => 'signers']),
            ])
            ->values();

        return response()->json([
            'q' => $q,
            'envelopes' => $envelopes,
            'recipients' => $recipients,
        ]);
    }
}
