<?php

namespace App\Http\Controllers\Dossier;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Services\Documents\DocumentAuditTrail;
use App\Services\Dossier\DossierExports;
use App\Services\Dossier\DossierFeature;
use App\Services\Dossier\Models\DossierExport;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download do dossiê por link autorizado com expiração (Q23).
 *
 * Três barreiras, todas obrigatórias:
 *
 * 1. sessão autenticada na organização dona do pedido (binding escopado → 404 para outra);
 * 2. permissão AGORA sobre cada envelope contido (e, no lote, ser quem pediu) → 404;
 * 3. URL assinada pela APP_KEY e dentro do prazo. Assinatura adulterada → 403; link vencido
 *    ou pedido expirado → 410, e o arquivo é apagado na hora.
 *
 * Cada download grava `envelope.downloaded` (tipo `dossier`) na trilha de cada envelope.
 */
class DossierDownloadController extends Controller
{
    public function __construct(
        private readonly DossierExports $exports,
        private readonly DocumentAuditTrail $audit,
    ) {}

    public function show(Request $request, DossierExport $dossierExport): Response
    {
        abort_unless(DossierFeature::enabled(CurrentOrganization::instance()->get()), 404);
        abort_unless($this->exports->canAccess($request->user(), $dossierExport), 404);

        if (! URL::hasCorrectSignature($request)) {
            abort(403, 'Link de download inválido.');
        }

        if (! URL::signatureHasNotExpired($request) || $dossierExport->isExpired()) {
            if ($dossierExport->isExpired() && $dossierExport->purged_at === null) {
                $this->exports->purge($dossierExport);
            }

            abort(410, 'Este link de download expirou. Peça o dossiê novamente.');
        }

        abort_unless($dossierExport->isReady(), 409, 'O dossiê ainda não está pronto.');
        abort_unless($this->exports->fileExists($dossierExport), 404, 'Arquivo indisponível.');

        $envelopes = Envelope::forOrganization($dossierExport->organization_id)->whereIn('id', $dossierExport->envelopeIds())->get();

        foreach ($envelopes as $envelope) {
            $this->audit->record($envelope, AuditEventType::EnvelopeDownloaded, [
                'type' => 'dossier',
                'dossier_export_ulid' => $dossierExport->ulid,
                'dossier_kind' => $dossierExport->kind,
                'sha256' => $dossierExport->sha256,
            ], $request->user(), $request);
        }

        $dossierExport->forceFill([
            'download_count' => $dossierExport->download_count + 1,
            'last_downloaded_at' => Carbon::now(),
        ])->save();

        $filename = $dossierExport->kind === DossierExport::KIND_SINGLE && $envelopes->first() !== null
            ? $envelopes->first()->display_code.'-dossie.zip'
            : 'dossies-'.$dossierExport->ulid.'.zip';

        return Storage::disk($dossierExport->storage_disk ?? 'documents')->download((string) $dossierExport->storage_path, $filename, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
