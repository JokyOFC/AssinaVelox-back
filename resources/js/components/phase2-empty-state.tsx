import { Link } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/**
 * Estado vazio de recurso não ativado para a conta (ROUTES §1.6): título, descrição e CTA
 * opcional. Renderizado dentro de um card branco.
 */
export function Phase2EmptyState({
    title,
    description,
    ctaHref,
    ctaLabel = 'Voltar para Documentos',
    extra,
}: {
    title: string;
    description: ReactNode;
    ctaHref?: string;
    ctaLabel?: string;
    extra?: ReactNode;
}) {
    return (
        <div className="border-border bg-card shadow-card rounded-xl border">
            <EmptyState
                icon={Sparkles}
                title={title}
                description={
                    <>
                        <Badge variant="phase" className="mb-3">
                            Não ativado
                        </Badge>
                        <br />
                        {description}
                    </>
                }
                action={
                    <>
                        {ctaHref && (
                            <Button asChild variant="outline">
                                <Link href={ctaHref}>{ctaLabel}</Link>
                            </Button>
                        )}
                        {extra}
                    </>
                }
            />
        </div>
    );
}
