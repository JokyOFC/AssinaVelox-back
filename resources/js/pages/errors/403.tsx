import { Head, Link } from '@inertiajs/react';
import { ShieldOff } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

export default function Forbidden({ message }: { message?: string | null }) {
    return (
        <>
            <Head title="Acesso negado" />
            <div className="rounded-xl border border-border bg-card shadow-card">
                <EmptyState
                    icon={ShieldOff}
                    title="Você não tem permissão para acessar esta página"
                    description={
                        message ??
                        'Peça a um administrador da organização para liberar o acesso ou volte para o painel.'
                    }
                    action={
                        <Button asChild>
                            <Link href={dashboard()}>Voltar ao painel</Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}
