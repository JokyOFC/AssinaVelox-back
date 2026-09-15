<?php

namespace App\Services\Identity;

use App\Models\SigningSession;
use App\Services\Signing\SignerContext;

/**
 * Props da etapa de vídeo curto na página pública (`pages/sign/show.tsx`, chave
 * `identity_video`; Fase 3 §3.3, docs/fase-3/captura-de-video.md §5).
 *
 * `null` quando a flag `identity_video` está desligada, o papel não registra aceite ou o vídeo
 * não foi exigido desta pessoa — e aí a chave nem aparece na página (`SignerPageProps`).
 * Sem sessão (tela `identify`) não há URL de envio: vídeo só depois do código.
 *
 * Os textos dizem para que serve, quem vê, por quanto tempo fica guardado e que o vídeo não é
 * usado para verificar identidade. O consentimento é marcado ANTES de ligar a câmera e a
 * versão do texto (SHA-256) vai junto no envio e fica na linha e na trilha.
 */
final class VideoStep
{
    public const TITLE = 'Vídeo curto para o registro do aceite';

    public const PURPOSE = 'Quem enviou o documento pediu um vídeo curto do seu rosto para anexar ao registro do seu '
        .'aceite, junto com a data, a hora e os demais dados da assinatura.';

    public const AUDIENCE = 'O vídeo fica guardado de forma cifrada e só pode ser assistido por quem enviou o documento e '
        .'pelas pessoas da conta dessa pessoa que têm acesso a este documento. Ele não aparece no PDF assinado '
        .'nem na página pública de verificação.';

    public const NOTICE = 'O vídeo não é usado para verificar sua identidade: a plataforma não compara rostos, não '
        .'analisa o vídeo e não confere quem aparece nele. É só um registro que você mesmo enviou.';

    public const CONSENT = 'Autorizo gravar e enviar um vídeo curto do meu rosto, sem som, para ficar guardado junto com o '
        .'registro do meu aceite pelo prazo informado, visível só para quem enviou o documento e para quem tem '
        .'acesso a ele na conta dessa pessoa.';

    public const FALLBACK = 'Se o navegador não gravar vídeo ou a câmera for negada, você pode enviar um vídeo curto '
        .'gravado pela câmera do aparelho (WebM ou MP4) ou abrir o link em outro aparelho. Sem o vídeo não é '
        .'possível concluir, porque quem enviou o documento o exigiu; se preferir não enviar, fale com essa pessoa '
        .'ou recuse o documento.';

    public function __construct(
        private readonly IdentityCaptures $captures,
        private readonly IdentityVideos $videos,
    ) {}

    /** Versão do texto de consentimento: SHA-256 do texto exibido. */
    public static function consentVersion(): string
    {
        return hash('sha256', self::CONSENT);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function props(SignerContext $context, ?SigningSession $session): ?array
    {
        if (! $this->captures->videoRequiredFor($context)) {
            return null;
        }

        $current = $session === null ? null : $this->videos->currentFor($session);
        $retentionDays = (int) config('assinavelox.capture.retention_days', 180);

        return [
            'required' => true,
            'complete' => $current !== null,
            'title' => self::TITLE,
            'label' => CaptureKind::Video->label(),
            'instructions' => CaptureKind::Video->instructions(),
            'purpose' => self::PURPOSE,
            'audience' => self::AUDIENCE,
            'notice' => self::NOTICE,
            'consent_label' => self::CONSENT,
            'consent_version' => self::consentVersion(),
            'fallback' => self::FALLBACK,
            'facing_mode' => CaptureKind::Video->facingMode(),
            // Gravado sem som: a página nunca pede o microfone.
            'audio' => false,
            'max_seconds' => IdentityVideos::secondsFor($this->videos->requirement($context->recipient)),
            'max_upload_kb' => IdentityVideos::maxUploadKb(),
            'video_bits_per_second' => max(100_000, (int) config('assinavelox.capture_video.video_bits_per_second', 1_000_000)),
            'accept' => ['video/webm', 'video/mp4'],
            // Mesma retenção das fotos (CapturePurge).
            'retention_days' => $retentionDays,
            'captured' => $current !== null,
            'captured_at' => $current?->captured_at->toIso8601String(),
            'duration_ms' => $current === null ? null : ($current->duration_ms ?? $current->declared_duration_ms),
            'size_bytes' => $current?->size_bytes,
            // POST multipart `video` + `consent` + `source` (camera|upload) + `duration_ms`. Só com sessão.
            'upload_url' => $session === null ? null : route('sign.capture.video.store', ['token' => $context->token]),
        ];
    }
}
