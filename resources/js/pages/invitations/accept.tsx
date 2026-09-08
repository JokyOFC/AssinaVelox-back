import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Clock, LogOut, XCircle } from 'lucide-react';
import { useState } from 'react';
import { AvatarInitials } from '@/components/avatar-initials';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateMedium } from '@/lib/format';
import { dashboard, login, logout, register } from '@/routes';
import { accept as invitationAccept } from '@/routes/invitations';
import { store as acceptInvitation } from '@/routes/invitations/accept';

export interface InvitationAcceptProps {
    token: string;
    invitation: {
        organization_name: string;
        organization_initials: string;
        role_label: string;
        email: string;
        invited_by: string;
        expires_at: string;
    } | null;
    state: 'valid' | 'expired' | 'revoked' | 'accepted';
    auth_state: 'guest' | 'same_user' | 'other_user';
}

const INVALID_COPY = {
    expired: {
        icon: Clock,
        title: 'Este convite expirou',
        text: 'Convites são válidos por 7 dias. Peça a um administrador da organização para enviar um novo convite.',
    },
    revoked: {
        icon: XCircle,
        title: 'Este convite foi revogado',
        text: 'O convite não é mais válido. Se acredita que isso é um engano, fale com quem o convidou.',
    },
    accepted: {
        icon: Check,
        title: 'Convite já aceito',
        text: 'Você já faz parte desta organização. Entre na sua conta para acessá-la.',
    },
} as const;

/** Aceitar convite (ROUTES §2.11): estados válido/expirado/revogado/aceito × guest/same_user/other_user. */
export default function InvitationAccept({
    token,
    invitation,
    state,
    auth_state,
}: InvitationAcceptProps) {
    const { auth } = usePage().props;
    const [processing, setProcessing] = useState(false);

    const acceptUrl = invitationAccept(token).url;

    const accept = () => {
        setProcessing(true);
        router.post(
            acceptInvitation(token).url,
            {},
            { onFinish: () => setProcessing(false) },
        );
    };

    if (state !== 'valid' || !invitation) {
        const copy = INVALID_COPY[state === 'valid' ? 'revoked' : state];
        const Icon = copy.icon;

        return (
            <>
                <Head title="Convite" />
                <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-6">
                    <span className="bg-muted text-text-secondary flex size-10 items-center justify-center rounded-[10px]">
                        <Icon className="size-5" />
                    </span>
                    <div>
                        <h1 className="text-[20px] font-bold">{copy.title}</h1>
                        <p className="text-text-secondary mt-2 text-[14px] leading-[1.55]">
                            {copy.text}
                        </p>
                    </div>
                    <Button asChild variant="outline" className="self-start">
                        <Link href={auth.user ? dashboard() : login()}>
                            {auth.user ? 'Ir para o painel' : 'Entrar'}
                        </Link>
                    </Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`Convite · ${invitation.organization_name}`} />
            <div className="border-border bg-card shadow-card flex flex-col gap-5 rounded-xl border p-6">
                <div className="flex items-center gap-3">
                    <AvatarInitials
                        initials={invitation.organization_initials}
                        tone="organization"
                        size="xl"
                    />
                    <div className="min-w-0">
                        <p className="text-muted-foreground text-[11px] font-bold tracking-[.18em] uppercase">
                            Convite para
                        </p>
                        <h1 className="truncate text-[20px] leading-tight font-bold">
                            {invitation.organization_name}
                        </h1>
                    </div>
                </div>
                <p className="text-text-secondary text-[14px] leading-[1.55]">
                    <b className="text-foreground">{invitation.invited_by}</b>{' '}
                    convidou{' '}
                    <b className="text-foreground">{invitation.email}</b> para
                    entrar como{' '}
                    <b className="text-foreground">{invitation.role_label}</b>.
                    O convite expira em{' '}
                    {formatDateMedium(invitation.expires_at)}.
                </p>

                {auth_state === 'same_user' && (
                    <Button size="lg" onClick={accept} disabled={processing}>
                        {processing && <Spinner />}
                        Entrar na organização
                    </Button>
                )}

                {auth_state === 'guest' && (
                    <div className="flex flex-col gap-2">
                        <Button asChild size="lg">
                            <Link
                                href={register({
                                    query: { invitation: token },
                                })}
                            >
                                Criar conta com {invitation.email}
                            </Link>
                        </Button>
                        <Button asChild variant="outline" size="lg">
                            <Link
                                href={login({ query: { intended: acceptUrl } })}
                            >
                                Já tenho conta · Entrar
                            </Link>
                        </Button>
                    </div>
                )}

                {auth_state === 'other_user' && (
                    <div className="flex flex-col gap-3">
                        <p className="border-warning-border bg-warning-bg text-warning rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                            Você está conectado como <b>{auth.user?.email}</b>,
                            mas o convite foi enviado para{' '}
                            <b>{invitation.email}</b>.
                        </p>
                        <Button
                            size="lg"
                            variant="outline"
                            onClick={() => router.post(logout.url())}
                        >
                            <LogOut className="size-4" />
                            Sair e entrar com {invitation.email}
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

InvitationAccept.layout = { hideHeader: true };
