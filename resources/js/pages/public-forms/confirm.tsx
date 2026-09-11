import { router, usePage } from '@inertiajs/react';
import {
    CircleCheck,
    CircleSlash,
    Clock,
    MailCheck,
    SearchX,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    PublicFormMessage,
    PublicFormShell,
} from '@/components/public-forms/public-form-shell';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Screen =
    | 'confirm'
    | 'done'
    | 'used'
    | 'expired'
    | 'invalid'
    | 'unavailable'
    | 'not_found';

interface ConfirmProps {
    screen: Screen;
    message?: string;
    outcome?: 'sent' | 'review' | 'failed';
    email_hint?: string | null;
    form?: { title: string; organization_name: string };
}

/**
 * Confirmação do e-mail (Fase 2 §2.2). O GET só mostra esta tela: quem
 * confirma é o botão (POST), para que clientes de e-mail que pré-carregam
 * links não gerem documentos sozinhos.
 */
export default function PublicFormConfirm(props: ConfirmProps) {
    return (
        <PublicFormShell
            title="Confirmar e-mail"
            organizationName={props.form?.organization_name}
        >
            <Screen {...props} />
        </PublicFormShell>
    );
}

PublicFormConfirm.layout = (page: ReactNode) => page;

function Screen({ screen, message, outcome, email_hint, form }: ConfirmProps) {
    const { url, props } = usePage<{ errors: Record<string, string> }>();
    const [processing, setProcessing] = useState(false);
    const error = props.errors?.confirmation;

    switch (screen) {
        case 'confirm':
            return (
                <PublicFormMessage
                    icon={<MailCheck className="size-6" />}
                    title="Confirme seu e-mail"
                >
                    <p>
                        Confirme que{' '}
                        {email_hint ? <b>{email_hint}</b> : 'este e-mail'} é seu
                        para gerar o documento do formulário{' '}
                        <b>{form?.title}</b>.
                    </p>
                    {error && (
                        <p
                            role="alert"
                            className="border-danger-border bg-danger-bg text-danger rounded-[10px] border px-3.5 py-2.5 text-[13px]"
                        >
                            {error}
                        </p>
                    )}
                    <div className="mt-2">
                        <Button
                            size="lg"
                            disabled={processing}
                            onClick={() =>
                                router.post(
                                    url,
                                    {},
                                    {
                                        onStart: () => setProcessing(true),
                                        onFinish: () => setProcessing(false),
                                    },
                                )
                            }
                        >
                            {processing && <Spinner />}
                            Confirmar e gerar documento
                        </Button>
                    </div>
                </PublicFormMessage>
            );
        case 'done':
            return outcome === 'failed' ? (
                <PublicFormMessage
                    tone="warning"
                    icon={<TriangleAlert className="size-6" />}
                    title="Não foi possível gerar o documento"
                >
                    <p>
                        Seu e-mail foi confirmado, mas o documento não pôde ser
                        gerado com estas respostas. Avise quem enviou o link.
                    </p>
                </PublicFormMessage>
            ) : (
                <PublicFormMessage
                    tone="success"
                    icon={<CircleCheck className="size-6" />}
                    title="E-mail confirmado"
                >
                    {outcome === 'sent' ? (
                        <p>
                            O documento foi gerado e o convite para assinar está
                            a caminho do seu e-mail.
                        </p>
                    ) : (
                        <p>
                            Recebemos suas respostas. A equipe de{' '}
                            <b>{form?.organization_name}</b> vai revisar e, se
                            estiver tudo certo, você recebe o convite para
                            assinar por e-mail.
                        </p>
                    )}
                </PublicFormMessage>
            );
        case 'expired':
            return (
                <PublicFormMessage
                    icon={<Clock className="size-6" />}
                    title="Link vencido"
                >
                    <p>{message}</p>
                </PublicFormMessage>
            );
        case 'used':
        case 'unavailable':
            return (
                <PublicFormMessage
                    icon={<CircleSlash className="size-6" />}
                    title={
                        screen === 'used'
                            ? 'Link já utilizado'
                            : 'Formulário indisponível'
                    }
                >
                    <p>{message}</p>
                </PublicFormMessage>
            );
        default:
            return (
                <PublicFormMessage
                    icon={<SearchX className="size-6" />}
                    title="Link inválido"
                >
                    <p>{message}</p>
                </PublicFormMessage>
            );
    }
}
