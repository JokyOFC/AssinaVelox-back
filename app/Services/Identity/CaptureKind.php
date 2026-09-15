<?php

namespace App\Services\Identity;

/**
 * O que pode ser capturado (Fase 2 §2.10 e Fase 3 §3.3). Nenhum tipo implica verificação: é o
 * que o participante enviou, e nada mais.
 *
 * `video` (F-VIDEO) é exigido por uma tabela própria (`identity_video_requirements`) e nunca
 * entra na lista de fotos: {@see self::photoCases()} é o que a captura de fotos da Fase 2
 * enxerga, idêntica ao que era antes do vídeo existir.
 */
enum CaptureKind: string
{
    case Selfie = 'selfie';
    case DocumentFront = 'document_front';
    case DocumentBack = 'document_back';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Selfie => 'Foto do rosto',
            self::DocumentFront => 'Foto do documento (frente)',
            self::DocumentBack => 'Foto do documento (verso)',
            self::Video => 'Vídeo curto',
        };
    }

    /**
     * Instrução curta para a etapa de captura na página pública.
     */
    public function instructions(): string
    {
        return match ($this) {
            self::Selfie => 'Tire uma foto do seu rosto, de frente e com boa iluminação.',
            self::DocumentFront => 'Fotografe a frente do seu documento com foto, sem cortar as bordas.',
            self::DocumentBack => 'Fotografe o verso do mesmo documento.',
            self::Video => 'Grave um vídeo curto do seu rosto, de frente e com boa iluminação. O vídeo é gravado sem som.',
        };
    }

    /**
     * Câmera sugerida ao navegador (`getUserMedia` → `facingMode`).
     */
    public function facingMode(): string
    {
        return in_array($this, [self::Selfie, self::Video], true) ? 'user' : 'environment';
    }

    public function isPhoto(): bool
    {
        return $this !== self::Video;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Só as fotos, na ordem canônica (rosto, frente, verso).
     *
     * @return list<self>
     */
    public static function photoCases(): array
    {
        return [self::Selfie, self::DocumentFront, self::DocumentBack];
    }

    /**
     * @return list<string>
     */
    public static function photoValues(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::photoCases());
    }
}
