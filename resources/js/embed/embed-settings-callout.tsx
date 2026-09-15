import { Link, usePage } from '@inertiajs/react';
import { AppWindow } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { edit as embedEdit } from '@/routes/integrations/embed';
import type { SharedProps } from '@/types';

/**
 * "Widget de assinatura" em API e integrações (Fase 3 §3.9, G-EMBED —
 * docs/fase-3/widget-embutido.md §4). Só aparece com a flag `embedded_signing` ligada;
 * desligada, a página é exatamente a de antes.
 */
export function EmbedSettingsCallout() {
    const { features } = usePage().props as unknown as Pick<
        SharedProps,
        'features'
    >;

    if (!(features?.embedded_signing ?? false)) {
        return null;
    }

    return (
        <div className="border-border bg-card shadow-card mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-5">
            <span className="flex min-w-0 items-start gap-3">
                <AppWindow className="text-primary mt-0.5 size-[18px] shrink-0" />
                <span className="min-w-0">
                    <span className="block text-[13.5px] font-semibold">
                        Widget de assinatura
                    </span>
                    <span className="text-muted-foreground block text-[12.5px]">
                        Deixe o participante assinar dentro do seu site.
                        Cadastre as origens que podem exibir o documento.
                    </span>
                </span>
            </span>
            <Button variant="outline" size="sm" asChild>
                <Link href={embedEdit.url()}>Configurar</Link>
            </Button>
        </div>
    );
}
