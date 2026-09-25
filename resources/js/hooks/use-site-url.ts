import { usePage } from '@inertiajs/react';

/** Destino padrão quando a prop compartilhada não chega (páginas de erro fora do grupo `web`). */
export const DEFAULT_SITE_URL = 'https://assinavelox.com.br';

/**
 * URL do site institucional (`config('assinavelox.site_url')`, prop compartilhada `site_url`).
 *
 * O app em app.assinavelox.com.br não tem página inicial própria — a rota `home` redireciona
 * para o login —, então o logo das cascas pública e de autenticação leva para o site, como em
 * qualquer produto. Páginas de erro podem ser renderizadas sem as props compartilhadas
 * (ver `public-layout.tsx`); daí o fallback.
 */
export function useSiteUrl(): string {
    const { site_url } = usePage().props as { site_url?: string };

    return typeof site_url === 'string' && site_url !== ''
        ? site_url
        : DEFAULT_SITE_URL;
}
