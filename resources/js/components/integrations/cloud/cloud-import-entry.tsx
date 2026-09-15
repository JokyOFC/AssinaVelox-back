import { usePage } from '@inertiajs/react';
import { CloudDownload } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { show as cloudImportShow } from '@/routes/cloud_import';
import type { SharedProps } from '@/types';

/**
 * Passo 1 do wizard — "Importar da nuvem" (Fase 3 §3.9, G-CONN). Só aparece com a flag
 * `cloud_import` ligada E algum provedor disponível (`cloud_import_available`, prop do wizard:
 * app registrado pelo proprietário ou simulador identificado). Sem nenhum, o passo é exatamente
 * o de antes — nada é anunciado que a página seguinte diria "ainda não disponível".
 *
 * Link comum (`<a>`, não `<Link>` do Inertia): a página de importação precisa ser carregada
 * inteira, porque só ela tem a CSP que libera o Google Picker e o Dropbox Chooser.
 */
export function CloudImportEntry({ envelopeId }: { envelopeId: string }) {
    const props = usePage().props as unknown as Pick<
        SharedProps,
        'features'
    > & {
        cloud_import_available?: boolean;
    };

    if (
        !props.features?.cloud_import ||
        props.cloud_import_available !== true
    ) {
        return null;
    }

    return (
        <div className="border-border bg-card shadow-card flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4">
            <span className="flex min-w-0 items-start gap-3">
                <span className="bg-primary-soft text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                    <CloudDownload className="size-[18px]" />
                </span>
                <span className="min-w-0">
                    <span className="block text-[13.5px] font-semibold">
                        Importar do Google Drive ou do Dropbox
                    </span>
                    <span className="text-muted-foreground block text-[12.5px]">
                        O arquivo passa pelas mesmas verificações de um envio do
                        computador.
                    </span>
                </span>
            </span>
            <Button variant="outline" size="sm" asChild>
                <a href={cloudImportShow.url(envelopeId)}>Importar da nuvem</a>
            </Button>
        </div>
    );
}
