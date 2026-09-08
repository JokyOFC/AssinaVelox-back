import { Head, Link } from '@inertiajs/react';
import { FileQuestion } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { dashboard, home } from '@/routes';

export default function NotFound({ message }: { message?: string | null }) {
    return (
        <>
            <Head title="Página não encontrada" />
            <div className="border-border bg-card shadow-card rounded-xl border">
                <EmptyState
                    icon={FileQuestion}
                    title="Página não encontrada"
                    description={
                        message ??
                        'O endereço pode estar errado ou o conteúdo foi removido. Verifique o link ou volte ao início.'
                    }
                    action={
                        <>
                            <Button asChild variant="outline">
                                <Link href={home()}>Página inicial</Link>
                            </Button>
                            <Button asChild>
                                <Link href={dashboard()}>Ir para o painel</Link>
                            </Button>
                        </>
                    }
                />
            </div>
        </>
    );
}
