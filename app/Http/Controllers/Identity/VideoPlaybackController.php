<?php

namespace App\Http\Controllers\Identity;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Identity\CaptureKind;
use App\Services\Identity\IdentityFeatures;
use App\Services\Identity\IdentityVideos;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Identity\VideoContainerInspector;
use App\Services\Identity\VideoEvidence;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Vídeos curtos no detalhe do envelope, para o REMETENTE (Fase 3 §3.3,
 * docs/fase-3/captura-de-video.md §6). Nunca na verificação pública.
 *
 * - `GET documentos/{envelope}/videos` (`envelopes.identity_videos.index`, JSON): lista, aviso,
 *   exigências e limites; cada vídeo disponível traz URLs ASSINADAS e curtas
 *   (`capture_video.playback_url_ttl_seconds`, 120 s) para reproduzir e baixar.
 * - `GET documentos/{envelope}/videos/{video}/arquivo` (`envelopes.identity_videos.file`): o
 *   arquivo, com Content-Type FIXO pelo contêiner lido dos bytes, Content-Disposition,
 *   `nosniff` e sem cache. Assinatura inválida ou vencida: 403; URL de outro usuário: 403.
 *
 * Quem vê: quem tem `view` no envelope — o mesmo público da página de evidências, onde ficam
 * as fotos. Flag `identity_video` desligada: 404 antes de qualquer outra decisão. Cada URL
 * servida grava UM `identity_video.accessed` na trilha (modo `play` ou `download`).
 */
class VideoPlaybackController extends Controller
{
    public function __construct(
        private readonly VideoEvidence $evidence,
        private readonly IdentityVideos $videos,
    ) {}

    public function index(Request $request, Envelope $envelope): JsonResponse
    {
        abort_unless(IdentityFeatures::identityVideo(CurrentOrganization::instance()->get()), 404);

        Gate::authorize('view', $envelope);

        return response()
            ->json($this->evidence->forEnvelope($envelope, $request->user()))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function file(Request $request, Envelope $envelope, string $video): Response
    {
        abort_unless(IdentityFeatures::identityVideo(CurrentOrganization::instance()->get()), 404);

        // URL assinada e curta: vencida ou adulterada não abre.
        abort_unless($request->hasValidSignature(), 403);

        $mode = (string) $request->query('mode');

        abort_unless(in_array($mode, ['play', 'download'], true), 404);
        abort_unless((string) $request->query('u') === (string) $request->user()?->getKey(), 403);

        Gate::authorize('view', $envelope);

        /** @var IdentityCapture|null $capture */
        $capture = IdentityCapture::withoutOrganizationScope()
            ->with('recipient')
            ->where('ulid', $video)
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->where('kind', CaptureKind::Video->value)
            ->whereNotNull('signature_acceptance_id')
            ->first();

        abort_if($capture === null || ! $capture->isAvailable(), 404);

        $bytes = $this->videos->read($capture);

        abort_if($bytes === null, 404);

        $nonce = (string) $request->query('n');

        if ($nonce !== '' && Cache::add('identity-video-access:'.hash('sha256', $nonce), true, VideoEvidence::playbackTtlSeconds() + 60)) {
            EnvelopeAudit::record($envelope, AuditEventType::IdentityVideoAccessed, [
                'capture_ulid' => $capture->ulid,
                'recipient_ulid' => $capture->recipient?->ulid,
                'mode' => $mode,
                'sha256' => $capture->sha256,
            ], $capture->recipient);
        }

        $filename = sprintf('video-%s.%s', strtolower($capture->ulid), VideoContainerInspector::extensionFor($capture->container));

        return response($bytes, 200, [
            'Content-Type' => VideoContainerInspector::mimeFor($capture->container),
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => ($mode === 'download' ? 'attachment' : 'inline').'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
