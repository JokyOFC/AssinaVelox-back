<?php

namespace App\Services\Branding;

/**
 * Limites da marca. Todos podem ser sobrescritos por `config('assinavelox.branding.*')`
 * (bloco ainda não criado em config/assinavelox.php — os padrões daqui valem até lá).
 */
final class BrandingLimits
{
    /** Cores padrão da plataforma (as mesmas do produto). */
    public const DEFAULT_PRIMARY = '#1257C9';

    public const DEFAULT_ACCENT = '#1257C9';

    public const MAX_DISPLAY_NAME = 80;

    public const MAX_EMAIL = 191;

    /** Formatos aceitos para o logo (MIME real, lido do conteúdo). */
    public const LOGO_MIMES = ['image/png', 'image/jpeg'];

    public static function logoMaxKb(): int
    {
        return max(16, (int) config('assinavelox.branding.logo_max_kb', 1024));
    }

    /** Lado máximo da imagem ENVIADA (lido do cabeçalho, antes de decodificar). */
    public static function logoMaxSourceSide(): int
    {
        return max(64, (int) config('assinavelox.branding.logo_max_source_side', 4000));
    }

    /** Pixels máximos da imagem enviada (antes de decodificar — evita bomba de descompressão). */
    public static function logoMaxSourcePixels(): int
    {
        return max(4096, (int) config('assinavelox.branding.logo_max_source_pixels', 12_000_000));
    }

    public static function logoMinSide(): int
    {
        return max(1, (int) config('assinavelox.branding.logo_min_side', 32));
    }

    /** Caixa de saída do PNG normalizado. */
    public static function logoOutputWidth(): int
    {
        return max(64, (int) config('assinavelox.branding.logo_output_width', 800));
    }

    public static function logoOutputHeight(): int
    {
        return max(32, (int) config('assinavelox.branding.logo_output_height', 400));
    }

    /**
     * @return array<string, mixed>
     */
    public static function forFront(): array
    {
        return [
            'logo_max_kb' => self::logoMaxKb(),
            'logo_max_source_side' => self::logoMaxSourceSide(),
            'logo_min_side' => self::logoMinSide(),
            'logo_output' => ['width' => self::logoOutputWidth(), 'height' => self::logoOutputHeight()],
            'logo_formats' => ['PNG', 'JPEG'],
            'display_name_max' => self::MAX_DISPLAY_NAME,
            'contrast' => [
                'primary_min' => ColorContrast::PRIMARY_MIN,
                'accent_min' => ColorContrast::ACCENT_MIN,
            ],
        ];
    }
}
