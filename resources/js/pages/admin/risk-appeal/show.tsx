import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { RiskStatusBadge } from '@/components/risk/risk-status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import {
    show as riskAppealShow,
    store as riskAppealStore,
} from '@/routes/risk/appeal';

export interface RiskAppealProps {
    status: string;
    status_label: string;
    changed_at: string | null;
    rules: { rule: string; label: string; explanation: string }[];
    review: {
        status: string;
        status_label: string;
        appeal_requested_at: string | null;
        decided_at: string | null;
        decision_label: string | null;
        open: boolean;
    } | null;
    can_request: boolean;
    max_message: number;
    response_days: number;
    support_email: string;
}

/**
 * Revisão de segurança da conta — lado da ORGANIZAÇÃO (LGPD art. 20). Mostra
 * o estado, os critérios que o motivaram (sem limiares) e o canal para pedir
 * revisão humana. Fica em `pages/admin/risk-appeal` só por ser a área de
 * arquivos do antifraude; a rota é do app do cliente.
 */
export default function RiskAppealShow({
    status,
    changed_at,
    rules,
    review,
    can_request,
    max_message,
    response_days,
    support_email,
}: RiskAppealProps) {
    const [message, setMessage] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        router.post(
            riskAppealStore.url(),
            { message },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => setError(errors.message ?? null),
                onSuccess: () => {
                    setError(null);
                    setMessage('');
                },
            },
        );
    };

    return (
        <>
            <Head title="Revisão de segurança" />
            <PageHeader
                title="Revisão de segurança da conta"
                subtitle="Nossos controles automáticos procuram sinais de uso indevido da plataforma. Nenhuma decisão automática altera documentos já enviados, aceites ou evidências."
                badge={<RiskStatusBadge status={status} />}
            />

            <div className="flex max-w-3xl flex-col gap-4">
                <section className="border-border bg-card shadow-card rounded-xl border p-5 text-[13.5px]">
                    {status === 'normal' && (
                        <p>
                            Esta conta está em situação normal. Não há restrição
                            nem observação ativa.
                        </p>
                    )}
                    {status === 'watch' && (
                        <p>
                            Esta conta está <strong>em observação</strong>
                            {changed_at &&
                                ` desde ${formatDateTime(changed_at)}`}
                            . O envio de documentos continua liberado.
                        </p>
                    )}
                    {status === 'restricted' && (
                        <p>
                            O <strong>envio de novos documentos</strong> está
                            suspenso
                            {changed_at &&
                                ` desde ${formatDateTime(changed_at)}`}{' '}
                            até a revisão de uma pessoa da equipe AssinaVelox.
                            Documentos já enviados continuam disponíveis para
                            leitura, assinatura e download.
                        </p>
                    )}
                </section>

                {rules.length > 0 && (
                    <section className="border-border bg-card shadow-card rounded-xl border p-5">
                        <h2 className="mb-3 text-[15px] font-semibold">
                            Critérios que motivaram a análise
                        </h2>
                        <ul className="flex flex-col gap-3 text-[13.5px]">
                            {rules.map((rule) => (
                                <li key={rule.rule}>
                                    <span className="font-medium">
                                        {rule.label}
                                    </span>
                                    <p className="text-text-secondary text-[13px]">
                                        {rule.explanation}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {review?.appeal_requested_at && review.open && (
                    <section className="border-border bg-card shadow-card rounded-xl border p-5 text-[13.5px]">
                        Pedido de revisão registrado em{' '}
                        {formatDateTime(review.appeal_requested_at)}. A equipe
                        responde em até {response_days} dia(s) útil(eis), por
                        e-mail.
                    </section>
                )}

                {review && !review.open && review.decided_at && (
                    <section className="border-border bg-card shadow-card rounded-xl border p-5 text-[13.5px]">
                        Última revisão concluída em{' '}
                        {formatDateTime(review.decided_at)}:{' '}
                        <strong>{review.decision_label}</strong>.
                    </section>
                )}

                {can_request && (
                    <section className="border-border bg-card shadow-card rounded-xl border p-5">
                        <h2 className="mb-1 text-[15px] font-semibold">
                            Pedir revisão humana
                        </h2>
                        <p className="text-muted-foreground mb-3 text-[13px]">
                            Conte o contexto (por exemplo, uma campanha legítima
                            de alto volume). Não envie documentos, senhas ou
                            dados pessoais de terceiros. Resposta em até{' '}
                            {response_days} dia(s) útil(eis).
                        </p>
                        <form onSubmit={submit} className="flex flex-col gap-3">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="appeal-message">
                                    Explicação
                                </Label>
                                <Textarea
                                    id="appeal-message"
                                    value={message}
                                    onChange={(e) => setMessage(e.target.value)}
                                    rows={5}
                                    maxLength={max_message}
                                    aria-invalid={!!error}
                                />
                                {error && (
                                    <p className="text-danger text-[12.5px]">
                                        {error}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="submit"
                                disabled={
                                    processing || message.trim().length < 20
                                }
                                className="self-start"
                            >
                                Enviar pedido de revisão
                            </Button>
                        </form>
                    </section>
                )}

                <p className="text-muted-foreground text-[12.5px]">
                    Também é possível pedir a revisão por e-mail:{' '}
                    {support_email}.
                </p>
            </div>
        </>
    );
}

RiskAppealShow.layout = {
    breadcrumbs: [{ title: 'Revisão de segurança', href: riskAppealShow() }],
};
