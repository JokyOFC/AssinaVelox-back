import { Link, usePage } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { create, index } from '@/routes/bulk_generations';

/**
 * "Gerar em lote" no cartão do modelo (Fase 3 §3.1). Não renderiza nada com a
 * flag `bulk_generation` desligada — a galeria de modelos fica exatamente como
 * antes.
 */
export function BulkGenerateButton({
    templateId,
    templateName,
}: {
    templateId: string;
    templateName: string;
}) {
    const { features } = usePage().props;

    if (!features.bulk_generation) {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    asChild
                    variant="outline"
                    size="icon-sm"
                    aria-label={`Gerar em lote a partir de ${templateName}`}
                >
                    <Link href={create.url(templateId)}>
                        <Layers />
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>Gerar em lote</TooltipContent>
        </Tooltip>
    );
}

/** Atalho para a lista de lotes no cabeçalho de Modelos (só com a flag ligada). */
export function BulkGenerationsLink() {
    const { features } = usePage().props;

    if (!features.bulk_generation) {
        return null;
    }

    return (
        <Button asChild variant="outline">
            <Link href={index.url()}>
                <Layers className="size-[15px]" />
                Lotes
            </Link>
        </Button>
    );
}
