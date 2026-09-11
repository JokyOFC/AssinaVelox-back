import { Link, usePage } from '@inertiajs/react';
import { Tablet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { create as inPersonCreate } from '@/routes/in_person';

/**
 * Atalho "Assinatura presencial" para o detalhe do documento (integração —
 * docs/fase-2/presencial-e-lote.md §6). Só aparece com a flag `in_person`
 * compartilhada ligada; leva à tela de início com o documento escolhido.
 */
export function StartInPersonLink({
    envelopeId,
    size = 'sm',
}: {
    envelopeId: string;
    size?: 'sm' | 'xs' | 'default';
}) {
    // `in_person` ainda não está no tipo `Features` compartilhado (integração).
    const enabled = Object.entries(usePage().props.features ?? {}).some(
        ([key, value]) => key === 'in_person' && value === true,
    );

    if (!enabled) {
        return null;
    }

    return (
        <Button asChild variant="outline" size={size}>
            <Link href={inPersonCreate({ query: { documento: envelopeId } })}>
                <Tablet className="size-4" />
                Assinatura presencial
            </Link>
        </Button>
    );
}
