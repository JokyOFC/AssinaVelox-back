import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Check, MailCheck } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

type Props = { status?: 'verification-link-sent' | null };

/** Verificar e-mail (ROUTES §2.3): card verde reaproveitado do mock de recuperação. */
export default function VerifyEmail({ status }: Props) {
    const { auth } = usePage().props;

    useEffect(() => {
        if (status === 'verification-link-sent') {
            toast.success('Novo link enviado');
        }
    }, [status]);

    return (
        <>
            <Head title="Verifique seu e-mail" />

            <div className="flex flex-col gap-4 rounded-xl border border-success-border bg-success-bg p-6">
                <span className="flex size-10 items-center justify-center rounded-[10px] bg-success-solid text-white">
                    {status === 'verification-link-sent' ? (
                        <Check className="size-5 stroke-[2.5]" />
                    ) : (
                        <MailCheck className="size-5" />
                    )}
                </span>
                <div>
                    <h1 className="text-[20px] font-bold">Verifique seu e-mail</h1>
                    <p className="mt-2 text-[14px] leading-[1.55] text-success">
                        Enviamos um link de confirmação para{' '}
                        <b>{auth.user?.email}</b>. Clique nele para ativar sua
                        conta. Se não aparecer em alguns minutos, confira a pasta
                        de spam.
                    </p>
                </div>
                <Form {...send.form()} className="flex flex-wrap items-center gap-3">
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="success"
                            size="sm"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Reenviar link
                        </Button>
                    )}
                </Form>
            </div>

            <p className="text-center text-[13.5px] text-text-secondary">
                E-mail errado?{' '}
                <Link
                    href={logout()}
                    method="post"
                    as="button"
                    className="font-semibold text-primary hover:underline"
                >
                    Sair e entrar com outra conta
                </Link>
            </p>
        </>
    );
}

VerifyEmail.layout = { hideHeader: true };
