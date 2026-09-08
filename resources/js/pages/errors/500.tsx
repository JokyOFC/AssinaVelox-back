import { Head } from '@inertiajs/react';
import { ServerCrash } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';

export default function ServerError({ message }: { message?: string | null }) {
    return (
        <>
            <Head title="Erro interno" />
            <div className="border-border bg-card shadow-card rounded-xl border">
                <EmptyState
                    icon={ServerCrash}
                    title="Algo deu errado do nosso lado"
                    description={
                        message ??
                        'Nossa equipe já foi avisada. Tente novamente em alguns instantes; se o problema persistir, fale com o suporte.'
                    }
                    action={
                        <Button
                            type="button"
                            onClick={() => window.location.reload()}
                        >
                            Tentar novamente
                        </Button>
                    }
                />
            </div>
        </>
    );
}
