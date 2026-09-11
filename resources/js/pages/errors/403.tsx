import { Head, Link, usePage } from '@inertiajs/react';
import { ShieldOff } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { dashboard, home } from '@/routes';
import { index as verifyIndex } from '@/routes/verify';

/**
 * 403 também alcança visitante sem conta (rotas públicas do signatário e da verificação).
 * Pedir a um administrador da organização, ou mandar a pessoa de volta ao painel, só faz
 * sentido para quem está autenticado; para os demais o texto e as ações mudam.
 */
export default function Forbidden({ message }: { message?: string | null }) {
    // Sem as props compartilhadas (resposta de erro fora do grupo `web`), `auth` pode faltar.
    const signedIn = Boolean(
        (usePage().props as { auth?: { user?: unknown } }).auth?.user,
    );

    return (
        <>
            <Head title="Acesso negado" />
            <div className="border-border bg-card shadow-card rounded-xl border">
                <EmptyState
                    icon={ShieldOff}
                    title="Você não tem permissão para acessar esta página"
                    description={
                        message ??
                        (signedIn
                            ? 'Peça a um administrador da organização para liberar o acesso ou abra o painel.'
                            : 'Este endereço exige uma permissão que esta sessão não tem. Se você recebeu um link para assinar, abra o link do e-mail mais recente.')
                    }
                    action={
                        signedIn ? (
                            <Button asChild>
                                <Link href={dashboard()}>Abrir o painel</Link>
                            </Button>
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
