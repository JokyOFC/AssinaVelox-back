<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Services\Documents\DocumentAuditTrail;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\EnvelopeDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download autorizado (ROUTES §1.2 `envelopes.download`): `type ∈ original | signed | evidence`.
 *
 * - `original` — o arquivo exatamente como foi enviado (PDF, DOCX ou imagem), disponível
 *   para quem pode ver o envelope, em qualquer status.
 * - `signed` — o PDF final (`kind=final`), só depois de `completed`.
 * - `evidence` — a página de evidências (`kind=evidence`), só depois de `completed`.
 *
 * Fase 2 §2.3: `?document={ulid}` escolhe o arquivo do envelope; sem ele vale o PRIMEIRO,
 * como na Fase 1. Um ULID de outro envelope ou de outra organização responde 404.
 *
 * Cada download emite `envelope.downloaded`. O disco é privado: nenhuma URL pública ou
 * assinada é gerada, e o binding do envelope já é escopado pela organização corrente
 * (arquivo de outra organização → 404, nunca 403 com vazamento de existência).
 */
class EnvelopeDownloadController extends Controller
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentAuditTrail $audit,
    ) {}

    public function show(Request $request, Envelope $envelope, string $type): Response
    {
        Gate::authorize('download', $envelope);

        abort_unless(in_array($type, ['original', 'signed', 'evidence'], true), 404);

        $requested = $this->requestedDocument($request, $envelope);

        if ($type !== 'original' && $envelope->status !== EnvelopeStatus::Completed) {
            abort(404, 'Arquivo disponível apenas após a conclusão do documento.');
        }

        [$version, $filename] = match ($type) {
            'original' => $this->original($envelope, $requested),
            'signed' => $this->finalVersion($envelope, $requested),
            default => $this->evidence($envelope, $requested),
        };

        if ($version === null || ! $this->storage->exists($version)) {
            abort(404, 'Arquivo indisponível.');
        }

        $payload = [
            'type' => $type,
            'document_version_ulid' => $version->ulid,
            'version_kind' => $version->kind->value,
            'sha256' => $version->sha256,
        ];

        if ($requested !== null) {
            $payload['document_ulid'] = $requested->ulid;
        }

        $this->audit->record($envelope, AuditEventType::EnvelopeDownloaded, $payload, $request->user(), $request);

        return $this->storage->stream($version, $filename, 'attachment');
    }

    /**
     * @return array{0: DocumentVersion|null, 1: string}
     */
    private function original(Envelope $envelope, ?Document $requested): array
    {
        $document = $requested ?? $envelope->document;

        if ($document === null) {
            return [null, ''];
        }

        $version = $document->versions()
            ->where('kind', DocumentVersionKind::Original->value)
            ->orderBy('version_number')
            ->first();

        if ($version === null) {
            return [null, ''];
        }

        $extension = pathinfo($version->storage_path, PATHINFO_EXTENSION) ?: 'pdf';

        return [$version, $this->storage->downloadFilename($document->original_filename, 'documento', $extension)];
    }

    /**
     * @return array{0: DocumentVersion|null, 1: string}
     */
    private function finalVersion(Envelope $envelope, ?Document $requested): array
    {
        if ($requested !== null) {
            $version = $requested->finalVersion ?? $this->versionOfKind($requested, DocumentVersionKind::Final);

            return [$version, sprintf('%s-%02d-assinado.pdf', $envelope->display_code, (int) $requested->position)];
        }

        $version = $envelope->finalVersion;

        if ($version === null) {
            $version = $this->versionOfKind($envelope->document, DocumentVersionKind::Final);
        }

        return [$version, $envelope->display_code.'-assinado.pdf'];
    }

    /**
     * @return array{0: DocumentVersion|null, 1: string}
     */
    private function evidence(Envelope $envelope, ?Document $requested): array
    {
        if ($requested !== null) {
            return [
                $this->versionOfKind($requested, DocumentVersionKind::Evidence),
                sprintf('%s-%02d-evidencias.pdf', $envelope->display_code, (int) $requested->position),
            ];
        }

        return [
            $this->versionOfKind($envelope->document, DocumentVersionKind::Evidence),
            $envelope->display_code.'-evidencias.pdf',
        ];
    }

    private function versionOfKind(?Document $document, DocumentVersionKind $kind): ?DocumentVersion
    {
        if ($document === null) {
            return null;
        }

        return $document->versions()
            ->where('kind', $kind->value)
            ->orderByDesc('version_number')
            ->first();
    }

    private function requestedDocument(Request $request, Envelope $envelope): ?Document
    {
        $ulid = $request->query('document');

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $document = EnvelopeDocuments::find($envelope, $ulid);

        abort_if($document === null, 404, 'Arquivo indisponível.');

        return $document;
    }
}
