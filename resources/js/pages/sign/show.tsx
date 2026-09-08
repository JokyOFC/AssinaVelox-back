import { Head } from '@inertiajs/react';
import { Check, Clock, FileText, Lock, ShieldCheck, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatDateMedium, formatDateTime, formatVerificationCode } from '@/lib/format';
import type { AuthMethod, EnvelopeStatus, FieldType, RecipientStatus, SigningOrder } from '@/types';

type SignerScreen = 'identify' | 'sign' | 'completed' | 'refused' | 'expired' | 'canceled' | 'already_signed_pending_others' | 'invalid';

export interface SignShowProps {
    token: string;
    screen: SignerScreen;
    sender: { organization_name: string; organization_initials: string; logo_url: null; user_name: string };
    envelope: { display_code: string; title: string; pages: number; sent_at: string; expires_at: string | null; status: EnvelopeStatus; completed_at: string | null };
    recipient: { first_name: string; name: string; role: string | null; email_masked: string; status: RecipientStatus; order: number };
    others: { name: string; role: string | null; order: number; status: RecipientStatus; signs_after_me: boolean }[];
    signing_order: SigningOrder;
    otp: { sent_at: string | null; expires_at: string | null; resend_available_at: string | null; attempts_left: number } | null;
    document: { pdf_url: string; page_thumb_url_template: string; page_sizes: { page: number; width_pt: number; height_pt: number }[] } | null;
    my_fields: { id: string; type: FieldType; page: number | 'all'; x: number; y: number; w: number; h: number; required: boolean; label: string | null; placeholder: string | null; prefill: string | null }[];
    other_fields: { recipient_name: string; role: string | null; type: FieldType; page: number; x: number; y: number; w: number; h: number; signed: boolean }[];
    signature_options: { draw: true; type: boolean; upload: boolean; fonts: string[] };
    consent_text: string;
    legal: { terms_url: string; privacy_url: string };
    receipt: { signed_at: string; verification_code: string; signed_sha256: string | null; ip: string; auth_label: string; download_url: string | null; final_pdf_available: boolean } | null;
    refusal: { refused_at: string; reason: string } | null;
    auth_methods?: AuthMethod[];
}

const STEP_BY_SCREEN: Record<SignerScreen, number | null> = {
    identify: 0,
    sign: 1,
    completed: 2,
    already_signed_pending_others: 2,
    refused: null,
    expired: null,
    canceled: null,
    invalid: null,
};

/**
 * Página pública do signatário (ROUTES §2.18; DESIGN §6.12) — casca inicial
 * com cabeçalho do documento e cards por estado. Wave B implementa OTP,
 * visualizador com campos, captura de assinatura, aceite e recusa.
 */
export default function SignShow({ screen, sender, envelope, recipient, others, receipt, refusal }: SignShowProps) {
    return (
        <>
            <Head title={`Assinar · ${envelope.title}`} />

            <div className="flex flex-wrap items-start gap-4">
                <div className="min-w-0 flex-[1.5_1_380px] rounded-[14px] border border-border bg-card p-[22px] shadow-card">
                    <p className="text-[11px] font-bold tracking-[.18em] text-muted-foreground uppercase">Documento</p>
                    <h1 className="mt-1.5 text-[20px] leading-[1.25] font-bold">{envelope.title}</h1>
                    <p className="mt-1 text-[13px] text-text-secondary tabular">
                        {envelope.display_code} · {envelope.pages} {envelope.pages === 1 ? 'página' : 'páginas'} · enviado por {sender.user_name} em {formatDateMedium(envelope.sent_at)}
                        {envelope.expires_at && ` · prazo até ${formatDateMedium(envelope.expires_at)}`}
                    </p>

                    <div className="mt-5 flex aspect-[1/1.3] w-full max-w-[680px] flex-col items-center justify-center gap-3 rounded bg-white p-[9%] text-center shadow-pdf">
                        <FileText className="size-10 text-primary-soft-border" />
                        <p className="text-[13px] text-muted-foreground">
                            {screen === 'identify'
                                ? 'O documento é exibido após a confirmação de identidade.'
                                : 'Visualizador do documento com campos de assinatura chega na Wave B.'}
                        </p>
                    </div>
                </div>

                <div className="flex min-w-0 max-w-[420px] flex-[1_1_320px] flex-col gap-4">
                    {screen === 'identify' && (
                        <PhaseCard icon={Lock} title={`Olá, ${recipient.first_name}`} description="Para proteger o documento, confirme sua identidade com um código enviado por e-mail.">
                            <div className="rounded-[10px] border border-border bg-sidebar p-3.5 text-[13px]">
                                Enviaremos um código para <b>{recipient.email_masked}</b>.
                                <p className="mt-1 text-[12px] text-muted-foreground">Código válido por 10 minutos.</p>
                            </div>
                            <Badge variant="phase">Fluxo de código · Wave B</Badge>
                        </PhaseCard>
                    )}

                    {screen === 'sign' && (
                        <PhaseCard icon={ShieldCheck} title="Identidade confirmada" description="Revise o documento, preencha seus campos e registre o aceite.">
                            <Badge variant="success">Identidade confirmada</Badge>
                            <Badge variant="phase">Captura de assinatura · Wave B</Badge>
                        </PhaseCard>
                    )}

                    {(screen === 'completed' || screen === 'already_signed_pending_others') && (
                        <PhaseCard icon={Check} tone="success" title={screen === 'completed' ? 'Documento assinado' : 'Você já assinou'} description={screen === 'completed' ? 'Seu aceite foi registrado com sucesso.' : `Aguardando ${others.filter((o) => o.status !== 'signed').length} signatário(s).`}>
                            {receipt && (
                                <dl className="grid grid-cols-[120px_1fr] gap-x-3 gap-y-1.5 text-[12.5px] tabular">
                                    <dt className="text-muted-foreground">Assinado em</dt>
                                    <dd>{formatDateTime(receipt.signed_at)}</dd>
                                    <dt className="text-muted-foreground">Autenticação</dt>
                                    <dd>{receipt.auth_label}</dd>
                                    <dt className="text-muted-foreground">Código</dt>
                                    <dd className="font-mono font-semibold">{formatVerificationCode(receipt.verification_code)}</dd>
                                </dl>
                            )}
                        </PhaseCard>
                    )}

                    {screen === 'refused' && (
                        <PhaseCard icon={XCircle} tone="danger" title="Assinatura recusada" description={refusal ? `Em ${formatDateTime(refusal.refused_at)} · Motivo: “${refusal.reason}”` : 'Você recusou assinar este documento.'} />
                    )}

                    {(screen === 'expired' || screen === 'canceled') && (
                        <PhaseCard icon={Clock} tone="neutral" title="Este documento não está mais disponível para assinatura" description={`${screen === 'expired' ? 'O prazo terminou.' : 'O remetente cancelou a solicitação.'} Em caso de dúvida, fale com ${sender.user_name} (${sender.organization_name}).`} />
                    )}

                    {screen === 'invalid' && <PhaseCard icon={XCircle} tone="neutral" title="Link inválido" description="Este link não corresponde a nenhum convite ativo. Verifique o e-mail recebido." />}

                    {others.length > 0 && (
                        <div className="rounded-[14px] border border-border bg-card p-[22px] shadow-card">
                            <h2 className="text-[15px] font-semibold">Outros signatários</h2>
                            <ul className="mt-3 flex flex-col gap-2 text-[13px]">
                                {others.map((o) => (
                                    <li key={`${o.order}-${o.name}`} className="flex items-center justify-between gap-2">
                                        <span className="truncate">
                                            {o.name}
                                            {o.role && <span className="text-muted-foreground"> · {o.role}</span>}
                                        </span>
                                        <span className="text-[12px] text-muted-foreground">
                                            {o.status === 'signed' ? 'Assinou' : o.signs_after_me ? 'assina depois de você' : 'pendente'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

function PhaseCard({
    icon: Icon,
    title,
    description,
    tone = 'primary',
    children,
}: {
    icon: typeof Lock;
    title: string;
    description?: string;
    tone?: 'primary' | 'success' | 'danger' | 'neutral';
    children?: React.ReactNode;
}) {
    const iconClass = {
        primary: 'bg-primary-soft text-primary',
        success: 'bg-success-solid text-white',
        danger: 'bg-danger-bg text-danger',
        neutral: 'bg-muted text-text-secondary',
    }[tone];

    return (
        <div className="flex flex-col gap-4 rounded-[14px] border border-border bg-card p-[22px] shadow-card">
            <span className={`flex size-[52px] items-center justify-center rounded-[14px] ${iconClass}`}>
                <Icon className="size-6" />
            </span>
            <div>
                <h2 className="text-[20px] leading-[1.25] font-bold">{title}</h2>
                {description && <p className="mt-1.5 text-[13.5px] leading-[1.55] text-text-secondary">{description}</p>}
            </div>
            {children}
        </div>
    );
}

SignShow.layout = (props: SignShowProps) => ({
    sender: props.sender,
    step: STEP_BY_SCREEN[props.screen],
});
