<?php

namespace App\Services\Identity;

use App\Models\Envelope;
use App\Models\User;
use App\Services\Identity\Models\IdentityCapture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Como o vídeo curto aparece para o REMETENTE (Fase 3 §3.3, docs/fase-3/captura-de-video.md §6):
 *
 * - {@see self::forEnvelope()}: bloco do detalhe do envelope (`GET envelopes.identity_videos.index`),
 *   com URLs ASSINADAS e curtas para reproduzir e baixar — só para quem tem `view` no envelope,
 *   o mesmo público da página de evidências, onde ficam as fotos.
 * - {@see self::pdfLines()}: a linha do PDF de evidências. Cita só existência, tipo, resumo SHA-256
 *   e a origem informada pelo navegador. O vídeo NUNCA é embutido no PDF.
 *
 * Nunca aparece na verificação pública. Vocabulário (T1): "vídeo enviado pelo participante",
 * sem verificação de identidade.
 */
final class VideoEvidence
{
    public const LABEL = 'Vídeo enviado pelo participante';

    public const NOTICE = 'Os vídeos abaixo foram enviados pelo próprio participante durante o aceite e são guardados '
        .'como registro. Não houve verificação de identidade: a plataforma não compara rostos, não analisa o '
        .'vídeo e não confere quem aparece nele. A origem (câmera ou arquivo do dispositivo) é a informada pelo '
        .'navegador do participante e não é verificada.';

    public const PDF_SUFFIX = 'O vídeo fica guardado à parte e não faz parte deste PDF.';

    public static function label(?string $source): string
    {
        return self::LABEL.' — '.match ($source) {
            'camera' => 'origem informada pelo navegador: câmera',
            'upload' => 'origem informada pelo navegador: arquivo do dispositivo',
            default => 'origem não informada pelo navegador',
        };
    }

    public static function playbackTtlSeconds(): int
    {
        return max(30, min(900, (int) config('assinavelox.capture_video.playback_url_ttl_seconds', 120)));
    }

    public function __construct(private readonly IdentityVideos $videos) {}

    /**
     * @return array{notice: string, items: list<array<string, mixed>>, requirements: array<string, array{max_seconds: int}>, limits: array{default_seconds: int, ceiling_seconds: int, max_upload_kb: int}}
     */
    public function forEnvelope(Envelope $envelope, ?User $viewer): array
    {
        $items = [];
        $expires = Carbon::now()->addSeconds(self::playbackTtlSeconds());

        foreach ($this->rows($envelope) as $capture) {
            $available = $capture->isAvailable();
            $source = in_array($capture->source, ['camera', 'upload'], true) ? $capture->source : null;

            $items[] = [
                'id' => $capture->ulid,
                'recipient_id' => $capture->recipient?->ulid,
                'recipient_name' => $capture->recipient?->name,
                'kind' => CaptureKind::Video->value,
                'kind_label' => CaptureKind::Video->label(),
                'label' => self::label($source),
                // Origem DECLARADA pelo navegador (`camera` | `upload` | null) — não verificada.
                'source' => $source,
                'source_label' => CaptureEvidence::sourceLabel($source),
                'container' => $capture->container,
                'container_label' => VideoContainerInspector::labelFor($capture->container),
                'mime_type' => VideoContainerInspector::mimeFor($capture->container),
                'duration_ms' => $capture->duration_ms,
                'declared_duration_ms' => $capture->declared_duration_ms,
                'size_bytes' => $capture->size_bytes,
                'width' => $capture->width ?: null,
                'height' => $capture->height ?: null,
                'sha256' => $capture->sha256,
                'captured_at' => $capture->captured_at->toIso8601String(),
                'consented_at' => $capture->consented_at?->toIso8601String(),
                'available' => $available,
                'purged_at' => $capture->purged_at?->toIso8601String(),
                'play_url' => $available && $viewer !== null ? $this->signedUrl($envelope, $capture, 'play', $viewer, $expires) : null,
                'download_url' => $available && $viewer !== null ? $this->signedUrl($envelope, $capture, 'download', $viewer, $expires) : null,
                'expires_at' => $available && $viewer !== null ? $expires->toIso8601String() : null,
            ];
        }

        return [
            'notice' => self::NOTICE,
            'items' => $items,
            'requirements' => $this->videos->requirementsForEnvelope($envelope),
            'limits' => [
                'default_seconds' => IdentityVideos::defaultSeconds(),
                'ceiling_seconds' => IdentityVideos::ceilingSeconds(),
                'max_upload_kb' => IdentityVideos::maxUploadKb(),
            ],
        ];
    }

    /**
     * Uma linha por participante com vídeo vinculado ao aceite, para o PDF de evidências.
     *
     * @return array<string, string> por ULID do destinatário
     */
    public function pdfLines(Envelope $envelope): array
    {
        $lines = [];

        foreach ($this->rows($envelope) as $capture) {
            $ulid = $capture->recipient?->ulid;

            if (! is_string($ulid)) {
                continue;
            }

            $duration = $capture->duration_ms ?? $capture->declared_duration_ms;
            $source = in_array($capture->source, ['camera', 'upload'], true) ? $capture->source : null;

            $lines[$ulid] = sprintf(
                '%s (%s%s) — SHA-256 %s. %s%s',
                self::label($source),
                VideoContainerInspector::labelFor($capture->container),
                $duration !== null ? ', '.number_format($duration / 1000, 1, ',', '').' s' : '',
                $capture->sha256,
                self::PDF_SUFFIX,
                $capture->isAvailable() ? '' : ' Arquivo excluído pela política de retenção.',
            );
        }

        return $lines;
    }

    /**
     * Vídeos vinculados a aceite deste envelope, presos ao envelope E à organização dele.
     *
     * @return iterable<IdentityCapture>
     */
    private function rows(Envelope $envelope): iterable
    {
        return IdentityCapture::withoutOrganizationScope()
            ->with('recipient:id,ulid,name')
            ->where('envelope_id', $envelope->getKey())
            ->where('organization_id', $envelope->organization_id)
            ->where('kind', CaptureKind::Video->value)
            ->whereNotNull('signature_acceptance_id')
            ->orderBy('id')
            ->get();
    }

    private function signedUrl(Envelope $envelope, IdentityCapture $capture, string $mode, User $viewer, Carbon $expires): string
    {
        return URL::temporarySignedRoute('envelopes.identity_videos.file', $expires, [
            'envelope' => $envelope->ulid,
            'video' => $capture->ulid,
            'mode' => $mode,
            // Presa a quem pediu: outro usuário com o link recebe 403.
            'u' => $viewer->getKey(),
            // Uma URL = um registro na trilha (o player pode pedir o arquivo mais de uma vez).
            'n' => Str::random(24),
        ]);
    }
}
