import { Head, Link, usePage } from '@inertiajs/react';
import { FileQuestion } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { dashboard, home } from '@/routes';
import { index as verifyIndex } from '@/routes/verify';

/**
 * 404 servido também às rotas públicas — inclusive `/assinar/{token}/...`, quando a sessão
 * de assinatura (30 min) já venceu.
 *
 * Por isso as ações olham para `auth.user`: um signatário não tem conta, não tem painel e
 * não tem administrador de organização. Mandá-lo para /dashboard só o levava ao login, onde
 * ele não tem credenciais. Sem usuário autenticado, os caminhos que existem para ele são a
 * página inicial e a verificação pública por código.
 */
export default function NotFound({ message }: { message?: string | null }) {
    const { auth } = usePage().props;

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
                        auth.user ? (
                            <>
                                <Button asChild variant="outline">
                                    <Link href={home()}>Página inicial</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={dashboard()}>
                                        Abrir o painel
                                    </Link>
                                </Button>
                            </>
                        ) : (
                            <>
                                <Button asChild variant="outline">
                                    <Link href={verifyIndex()}>
                                        Verificar documento
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link href={home()}>Página inicial</Link>
                                </Button>
                            </>
                        )
                    }
                />
            </div>
        </>
    );
}
