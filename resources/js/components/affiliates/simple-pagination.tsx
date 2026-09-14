import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

/** Anterior / próxima + "x–y de z" para as listas do programa de afiliados. */
export function SimplePagination<T>({ page }: { page: Paginated<T> }) {
    if (page.meta.last_page <= 1) {
        return null;
    }

    return (
        <div className="text-muted-foreground flex items-center justify-between gap-3 px-1 pt-3 text-[12.5px]">
            <span>
                {page.meta.from ?? 0}–{page.meta.to ?? 0} de {page.meta.total}
            </span>
            <div className="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!page.links.prev}
                    asChild={!!page.links.prev}
                >
                    {page.links.prev ? (
                        <Link href={page.links.prev} preserveScroll>
                            Anterior
                        </Link>
                    ) : (
                        <span>Anterior</span>
                    )}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!page.links.next}
                    asChild={!!page.links.next}
                >
                    {page.links.next ? (
                        <Link href={page.links.next} preserveScroll>
                            Próxima
                        </Link>
                    ) : (
                        <span>Próxima</span>
                    )}
                </Button>
            </div>
        </div>
    );
}
