import { Head } from '@inertiajs/react';
import { Code } from 'lucide-react';
import { ConnectorsCallout } from '@/components/integrations/cloud/connectors-callout';
import { EmbedSettingsCallout } from '@/embed/embed-settings-callout';
import { PageHeader } from '@/components/page-header';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { SegmentedControl } from '@/components/segmented-control';
import { Button } from '@/components/ui/button';
import { index as envelopesIndex } from '@/routes/envelopes';
import { index as integrationsIndex } from '@/routes/integrations';

/**
 * API e integrações com a API v1 e os webhooks desligados para a organização (ROUTES §2.21;
 * DESIGN §6.9/6.10). A Fase 2 já existe: o texto diz que ela não está disponível AQUI (plano ou
 * interruptor), sem prometer data. O widget embutido depende da API v1, então o cartão dele não
 * aparece nesta tela (EmbedFeature exige `api_integrations` — revisão adversarial G).
 */
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
                                title: 'Ainda não disponível para esta organização',
                            },
                            {
                                value: 'keys',
                                label: 'Chaves e webhooks',
                                disabled: true,
                                title: 'Ainda não disponível para esta organização',
                            },
                            {
                                value: 'logs',
                                label: 'Logs',
                                disabled: true,
                                title: 'Ainda não disponível para esta organização',
                            },
                        ]}
                    />
                }
            />
            <ConnectorsCallout />
            <EmbedSettingsCallout />
            <Phase2EmptyState
                title="A API pública, as chaves e os webhooks ainda não estão disponíveis para esta organização"
                description="Quando estiverem, você poderá criar solicitações por API, receber eventos por webhook e consultar os logs de entrega. Enquanto isso, use a interface para enviar documentos."
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
