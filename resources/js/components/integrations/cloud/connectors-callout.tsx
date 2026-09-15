import { Link, usePage } from '@inertiajs/react';
import { CloudDownload, Workflow } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { show as hubspotShow } from '@/routes/integrations/hubspot';
import type { SharedProps } from '@/types';

/**
 * "Conectores" em API e integrações (Fase 3 §3.9, G-CONN). Só aparece com `hubspot` ou
 * `cloud_import` ligada; desligadas, a página é exatamente a de antes. A importação da nuvem
 * só é anunciada com algum provedor de verdade disponível (`cloud_import_available`, prop da
 * página): sem app registrado, a linha diz isso em vez de prometer.
 */
export function ConnectorsCallout() {
    const props = usePage().props as unknown as Pick<
        SharedProps,
        'features'
    > & {
        cloud_import_available?: boolean;
    };
    const hubspot = props.features?.hubspot ?? false;
    const cloud = props.features?.cloud_import ?? false;
    const cloudAvailable = props.cloud_import_available === true;

    if (!hubspot && !cloud) {
        return null;
    }

    return (
        <div className="border-border bg-card shadow-card mb-5 flex flex-col gap-3.5 rounded-xl border p-5">
            <Heading
                variant="small"
                title="Conectores"
                description="Aplicativos de terceiros ligados à sua organização."
            />
            {hubspot && (
                <div className="border-border flex flex-wrap items-center justify-between gap-3 rounded-[10px] border p-3.5">
                    <span className="flex min-w-0 items-start gap-3">
                        <Workflow className="text-primary mt-0.5 size-[18px] shrink-0" />
                        <span className="min-w-0">
                            <span className="block text-[13.5px] font-semibold">
                                HubSpot
                            </span>
                            <span className="text-muted-foreground block text-[12.5px]">
                                Envie para assinatura a partir de um workflow e
                                acompanhe o estado no negócio ou no contato.
                            </span>
                        </span>
                    </span>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={hubspotShow.url()}>Abrir</Link>
                    </Button>
                </div>
            )}
            {cloud && (
                <p className="text-muted-foreground flex items-start gap-2 text-[12.5px]">
                    <CloudDownload className="mt-0.5 size-3.5 shrink-0" />
                    {cloudAvailable
                        ? 'Google Drive e Dropbox: importe arquivos no passo “Documento” de cada envio.'
                        : 'Google Drive e Dropbox: ainda não disponível. Aguardando o app registrado pelo proprietário da plataforma.'}
                </p>
            )}
        </div>
    );
}
