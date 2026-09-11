<?php

namespace App\Services\Identity;

/**
 * O que pode ser fotografado na captura simples (Fase 2 §2.10). Nenhum tipo implica
 * verificação: é a imagem que o participante enviou, e nada mais.
 */
enum CaptureKind: string
{
    case Selfie = 'selfie';
    case DocumentFront = 'document_front';
    case DocumentBack = 'document_back';

    public function label(): string
    {
        return match ($this) {
            self::Selfie => 'Foto do rosto',
            self::DocumentFront => 'Foto do documento (frente)',
            self::DocumentBack => 'Foto do documento (verso)',
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
        };
    }

    /**
     * Câmera sugerida ao navegador (`getUserMedia` → `facingMode`).
     */
    public function facingMode(): string
    {
        return $this === self::Selfie ? 'user' : 'environment';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
