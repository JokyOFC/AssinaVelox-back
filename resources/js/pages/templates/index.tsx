import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { SelectableChip } from '@/components/filter-bar';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { index as envelopesIndex } from '@/routes/envelopes';
import { index as templatesIndex } from '@/routes/templates';

const CATEGORIES = ['Todos', 'Locação', 'Vendas', 'Jurídico', 'RH'];

/** Placeholder Fase 2 de Modelos (ROUTES §2.21; DESIGN §6.7). */
export default function TemplatesIndex() {
    return (
        <>
            <Head title="Modelos" />
            <PageHeader
                title="Modelos de documentos"
                subtitle="Crie solicitações a partir de modelos reutilizáveis com campos já posicionados."
                actions={
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span>
                                <Button disabled>
                                    <Plus className="size-[15px]" strokeWidth={2.5} />
                                    Novo modelo
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>Disponível na Fase 2</TooltipContent>
                    </Tooltip>
                }
            />
            <div className="flex flex-wrap gap-2">
                {CATEGORIES.map((category, index) => (
                    <SelectableChip key={category} selected={index === 0} disabled>
                        {category}
                    </SelectableChip>
                ))}
            </div>
            <Phase2EmptyState
                title="Modelos estarão disponíveis na Fase 2"
                description="Enquanto isso, duplique um documento existente para reaproveitar signatários e campos."
                ctaHref={envelopesIndex.url()}
                ctaLabel="Ir para Documentos"
            />
        </>
    );
}

TemplatesIndex.layout = {
    breadcrumbs: [{ title: 'Modelos', href: templatesIndex() }],
};
