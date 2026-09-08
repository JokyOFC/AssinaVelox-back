import { Head } from '@inertiajs/react';
import { Code } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { SegmentedControl } from '@/components/segmented-control';
import { Button } from '@/components/ui/button';
import { index as envelopesIndex } from '@/routes/envelopes';
import { index as integrationsIndex } from '@/routes/integrations';

/** Placeholder Fase 2 de API e integrações (ROUTES §2.21; DESIGN §6.9/6.10). */
export default function IntegrationsIndex() {
    return (
        <>
            <Head title="API e integrações" />
            <PageHeader
                title="API e integrações"
                subtitle="Documentação da API pública, chaves de acesso e webhooks."
                actions={
                    <SegmentedControl
                        value="docs"
                        onChange={() => undefined}
                        options={[
                            {
                                value: 'docs',
                                label: 'Documentação',
                                disabled: true,
                                title: 'Disponível na Fase 2',
                            },
                            {
                                value: 'keys',
                                label: 'Chaves e webhooks',
                                disabled: true,
                                title: 'Disponível na Fase 2',
                            },
                            {
                                value: 'logs',
                                label: 'Logs',
                                disabled: true,
                                title: 'Disponível na Fase 2',
                            },
                        ]}
                    />
                }
            />
            <Phase2EmptyState
                title="A API pública, chaves e webhooks estarão disponíveis na Fase 2"
                description="Você poderá criar solicitações por API, receber eventos por webhook e consultar logs de entrega. Enquanto isso, use a interface para enviar documentos."
                ctaHref={envelopesIndex.url()}
                ctaLabel="Ir para Documentos"
                extra={
                    <Button
                        variant="dashed"
                        size="sm"
                        disabled
                        title="Em breve"
                    >
                        <Code className="size-[15px]" />
                        Avisar-me quando lançar
                    </Button>
                }
            />
        </>
    );
}

IntegrationsIndex.layout = {
    breadcrumbs: [{ title: 'API e integrações', href: integrationsIndex() }],
};
