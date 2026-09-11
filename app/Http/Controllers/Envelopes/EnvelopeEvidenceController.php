<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\DocumentVersionKind;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Resources\AuditEventResource;
use App\Http\Resources\EnvelopeDetailResource;
use App\Models\AuditEvent;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Services\Verification\EvidenceDossier;
use App\Services\Verification\HashLedger;
use App\Services\Verification\SignatureNarrative;
use App\Support\TaxId;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de evidências do envelope (ROUTES §2.8), somente leitura.
 *
 * Autorização: `Gate::authorize('view')` sobre a policy do envelope. O binding de rota já é
 * escopado por organização ({@see BelongsToOrganization::resolveRouteBinding()}),
 * de modo que um envelope de outra organização não chega sequer ao controller — 404, não 403,
 * porque 403 já confirmaria que o documento existe.
 *
 * O PDF do relatório vem por `envelopes.download?type=evidence` (incremento 2). Esta página é
 * a versão navegável do mesmo conjunto de fatos; ela não gera arquivo nenhum.
 */
class EnvelopeEvidenceController extends Controller
{
    public function __construct(private readonly EvidenceDossier $dossier) {}

    public function show(Request $request, Envelope $envelope): Response
    {
        Gate::authorize('view', $envelope);

        $envelope->load([
            'folder',
            'creator',
            'organization',
            'recipients.acceptance',
            'document.currentVersion',
            'document.originalVersion',
            'sentVersion',
            'finalVersion',
            'verificationRecord.certificateReference',
        ]);

        $detail = EnvelopeDetailResource::make($envelope)->resolve($request);
        $record = $envelope->verificationRecord;

        $events = AuditEvent::query()
            ->with(['recipient', 'actorUser', 'organization'])
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $signature = SignatureNarrative::for($envelope, $record);
        $hashes = HashLedger::values($envelope, $record);

        return Inertia::render('envelopes/evidence', [
            'envelope' => Arr::only($detail, ['id', 'display_code', 'verification_code', 'title', 'status', 'status_label', 'created_at', 'sent_at', 'completed_at', 'document', 'downloads', 'signing_order']),
            // Mesmo nome da prop compartilhada `organization`: mescla os campos do shell (ROUTES §0.3).
            'organization' => [
                ...(HandleInertiaRequests::currentOrganizationProps() ?? []),
                'name' => $envelope->organization->name,
                'legal_name' => $envelope->organization->legal_name,
                'tax_id_masked' => TaxId::mask($envelope->organization->tax_id),
            ],
            'recipients' => $this->dossier->recipients($envelope, $events),
            'events' => AuditEventResource::collection($events)->resolve($request),
            /*
             * Ao contrário da página pública, o dossiê mostra os QUATRO resumos: quem o lê já
             * pode ver o documento. `items` traz cada um com a explicação de quais bytes ele
             * identifica (docs/juridico/declaracao-de-aceite.md §5.1); `signed_sha256` é o nome
             * antigo do resumo final, mantido pelo contrato da página.
             */
            'hashes' => [
                'original_sha256' => $hashes['original'] ?? '',
                'sent_sha256' => $hashes['sent'],
                'consolidated_sha256' => $hashes['consolidated'],
                'evidence_sha256' => $this->evidenceHash($envelope),
                'final_sha256' => $hashes['final'],
                'signed_sha256' => $hashes['final'],
                'items' => HashLedger::items($envelope, $record),
                'primer' => HashLedger::primer(),
            ],
            'signature_status' => $signature['status'],
            'signature_profile' => $signature['profile'],
            'signature' => [
                'state' => $signature['state'],
                'status' => $signature['status'],
                'label' => $signature['label'],
                'statement' => $signature['statement'],
                'profile' => $signature['profile'],
            ],
            'validation' => $signature['validation'],
            'validation_summary' => $signature['validation']['summary'] ?? null,
            'certificate' => $this->certificateProps($signature['certificate']),
            'notes' => $this->dossier->notes(),
            // Fase 2 §2.3 (aditivo): um item por arquivo, com os resumos e quem aceitou.
            'documents' => $this->dossier->documents($envelope, $request->user()?->can('download', $envelope) ?? false),
            'terms_version' => $envelope->terms_version,
            'verify_url' => $envelope->verification_code
                ? route('verify.show', ['code' => $envelope->verification_code])
                : route('verify.index'),
        ]);
    }

    /**
     * Contrato de `certificate` da página (ROUTES §2.8): `subject`/`issuer`/`serial`/
     * `valid_from`/`valid_to`/`policy`, mais o ambiente — um certificado de teste **precisa**
     * aparecer como tal e nunca como ICP-Brasil (arquitetura §2).
     *
     * @param  array<string, mixed>|null  $certificate
     * @return array<string, mixed>|null
     */
    private function certificateProps(?array $certificate): ?array
    {
        if ($certificate === null) {
            return null;
        }

        return [
            'subject' => $certificate['subject'] ?? $certificate['subject_cn'],
            'subject_cn' => $certificate['subject_cn'],
            'issuer' => $certificate['issuer'] ?? $certificate['issuer_cn'],
            'issuer_cn' => $certificate['issuer_cn'],
            'serial' => $certificate['serial'],
            'valid_from' => $certificate['valid_from'],
            'valid_to' => $certificate['valid_to'],
            'policy' => $certificate['policy'],
            'environment' => $certificate['environment'],
            'environment_label' => $certificate['environment_label'],
            'is_test' => $certificate['is_test'],
        ];
    }

    /**
     * Resumo da página de evidências anexada ao arquivo final (`kind = evidence`), quando a
     * finalização já a produziu.
     */
    private function evidenceHash(Envelope $envelope): ?string
    {
        $document = $envelope->document;

        if ($document === null) {
            return null;
        }

        $sha256 = DocumentVersion::query()
            ->where('document_id', $document->getKey())
            ->where('kind', DocumentVersionKind::Evidence->value)
            ->orderByDesc('version_number')
            ->value('sha256');

        return is_string($sha256) && $sha256 !== '' ? $sha256 : null;
    }
}
