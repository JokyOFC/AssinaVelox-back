<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Resources\AuditEventResource;
use App\Http\Resources\EnvelopeDetailResource;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Support\IpDisplay;
use App\Support\TaxId;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de evidências (ROUTES §2.8). Dados básicos reais; certificado/hashes finais dependem
 * do pipeline de finalização — // TODO(Wave C).
 */
class EnvelopeEvidenceController extends Controller
{
    public function show(Request $request, Envelope $envelope): Response
    {
        Gate::authorize('view', $envelope);

        $envelope->load(['folder', 'creator', 'organization', 'recipients.acceptance', 'document.currentVersion', 'document.originalVersion', 'finalVersion', 'verificationRecord']);

        $detail = EnvelopeDetailResource::make($envelope)->resolve($request);
        $record = $envelope->verificationRecord;

        $events = AuditEvent::query()
            ->with(['recipient', 'actorUser'])
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return Inertia::render('envelopes/evidence', [
            'envelope' => Arr::only($detail, ['id', 'display_code', 'verification_code', 'title', 'status', 'status_label', 'created_at', 'sent_at', 'completed_at', 'document', 'downloads', 'signing_order']),
            // Mesmo nome da prop compartilhada `organization`: mescla os campos do shell (ROUTES §0.3).
            'organization' => [
                ...(HandleInertiaRequests::currentOrganizationProps() ?? []),
                'name' => $envelope->organization->name,
                'legal_name' => $envelope->organization->legal_name,
                'tax_id_masked' => TaxId::mask($envelope->organization->tax_id),
            ],
            'recipients' => $envelope->recipients->map(function (Recipient $recipient) use ($envelope): array {
                $acceptance = $recipient->acceptance;

                return [
                    'name' => $recipient->name,
                    'email' => $recipient->email,
                    'role' => null,
                    'status' => $recipient->status->value,
                    'status_label' => $recipient->status->label(),
                    'auth_methods' => [$recipient->auth_method->value],
                    'signature_kind' => $acceptance?->signature_kind?->value,
                    'signature_image_url' => null, // TODO(Wave C): rota autorizada para a imagem
                    'sent_at' => $recipient->last_notified_at?->toIso8601String(),
                    'viewed_at' => null,
                    'otp_verified_at' => null,
                    'signed_at' => $recipient->signed_at?->toIso8601String(),
                    'refused_at' => $recipient->refused_at?->toIso8601String(),
                    'refusal_reason' => $recipient->refusal_reason,
                    // `evidence_show_ip` da organização (arquitetura §3.1) vale aqui como
                    // vale na trilha e no card do signatário — ver App\Support\IpDisplay.
                    'ip' => IpDisplay::for($acceptance?->ip_address, $envelope->organization),
                    'user_agent' => $acceptance?->user_agent,
                    'geo_label' => null,
                    'consent_text' => $acceptance?->consent_statement,
                ];
            })->values()->all(),
            'events' => AuditEventResource::collection($events)->resolve($request),
            'hashes' => [
                'original_sha256' => (string) ($record->original_sha256 ?? $envelope->document?->originalVersion->sha256 ?? ''),
                'signed_sha256' => $record->final_sha256 ?? $envelope->finalVersion?->sha256,
                'evidence_sha256' => null,
            ],
            'signature_status' => $record?->signature_status->value ?? 'none',
            'certificate' => null, // TODO(Wave C): certificate_references do registro de verificação
            'verify_url' => $envelope->verification_code
                ? route('verify.show', ['code' => $envelope->verification_code])
                : route('verify.index'),
        ]);
    }
}
