import { Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { index as adminOrganizations } from '@/routes/admin/organizations';

type Feature =
    | 'admin_billing'
    | 'admin_users'
    | 'admin_audit'
    | 'admin_settings'
    | 'templates'
    | 'api_integrations';

export interface Phase2PlaceholderProps {
    feature: Feature;
    title: string;
    subtitle: string;
}

const FALLBACK: Record<Feature, { title: string; subtitle: string }> = {
    admin_billing: {
        title: 'Planos e faturamento',
        subtitle: 'Visão consolidada de planos, MRR e pagamentos da plataforma.',
    },
    admin_users: {
        title: 'Usuários da plataforma',
        subtitle: 'Todos os usuários cadastrados, com organizações e último acesso.',
    },
    admin_audit: {
        title: 'Logs e auditoria',
        subtitle: 'Trilha de ações da equipe interna e eventos de sistema.',
    },
    admin_settings: {
        title: 'Configurações globais',
        subtitle: 'Parâmetros da plataforma, limites e integrações.',
    },
    templates: {
        title: 'Modelos',
        subtitle: 'Modelos reutilizáveis de documentos.',
    },
    api_integrations: {
        title: 'API e integrações',
        subtitle: 'Chaves, webhooks e documentação.',
    },
};

/** Placeholder de telas do painel interno na Fase 2 (ROUTES §1.5 / §2.21). */
export default function AdminPlaceholder(props: Partial<Phase2PlaceholderProps>) {
    const { url } = usePage();
    const feature: Feature = props.feature ?? 'admin_settings';
    const title = props.title ?? FALLBACK[feature].title;
    const subtitle = props.subtitle ?? FALLBACK[feature].subtitle;

    return (
        <>
            <Head title={title} />
            <PageHeader title={title} subtitle={subtitle} />
            <Phase2EmptyState
                title={`${title} estará disponível na Fase 2`}
                description={`Esta área do painel interno ainda não foi implementada (${url}). Use a lista de clientes para dar suporte.`}
                ctaHref={adminOrganizations.url()}
                ctaLabel="Ir para Clientes"
            />
        </>
    );
}

AdminPlaceholder.layout = {
    breadcrumbs: [],
};
