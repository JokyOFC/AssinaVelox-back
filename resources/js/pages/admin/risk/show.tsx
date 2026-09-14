import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import {
    EvidenceList,
    type EvidenceRow,
} from '@/components/risk/evidence-list';
import { RiskNav } from '@/components/risk/risk-nav';
import {
    RiskReviewStatusBadge,
    RiskStatusBadge,
} from '@/components/risk/risk-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as adminOrganizationShow } from '@/routes/admin/organizations';
import {
    decide as adminRiskDecide,
    index as adminRiskIndex,
} from '@/routes/admin/risk';
import type { RiskReviewSummary } from './index';

interface Signal {
    id: string;
    rule: string;
    label: string;
    explanation: string | null;
    can_restrict: boolean;
    score: number;
    occurred_at: string;
    envelope: string | null;
    evidence: EvidenceRow[];
}

interface HistoryEvent {
    id: string;
    occurred_at: string;
    action: string;
    label: string;
    actor: string;
    details: { label: string; value: string }[];
    ip: string | null;
}

interface DecisionOption {
    value: string;
    label: string;
    description: string;
}

export interface AdminRiskShowProps {
    review: RiskReviewSummary & {
        decision: string | null;
        decision_label: string | null;
        decision_reason: string | null;
        reviewer: string | null;
        decided_at: string | null;
        restricted_at: string | null;
        status_before: string | null;
        appeal: {
            requested_at: string;
            requested_by: string;
            message: string;
        } | null;
    };
    organization: {
        id: string;
        name: string;
        created_at: string | null;
        risk_status: string;
        risk_status_label: string;
        risk_status_changed_at: string | null;
    };
    signals: Signal[];
    history: HistoryEvent[];
    decisions: DecisionOption[];
    can_decide: boolean;
    min_reason: number;
}

function Section({
    title,
    children,
    className,
}: {
    title: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'border-border bg-card shadow-card rounded-xl border p-5',
                className,
            )}
        >
            <h2 className="mb-3 text-[15px] font-semibold">{title}</h2>
            {children}
        </section>
    );
}

/**
 * Painel interno › Antifraude › Caso. Regra + evidência de cada sinal,
 * histórico de estados e decisão humana com motivo obrigatório.
 */
export default function AdminRiskShow({
    review,
    organization,
    signals,
    history,
    decisions,
    can_decide,
    min_reason,
}: AdminRiskShowProps) {
    const [decision, setDecision] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (decision === null) {
            setErrors({ decision: 'Escolha uma decisão.' });

            return;
        }

        router.post(
            adminRiskDecide.url(review.id),
            { decision, reason },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (next) => setErrors(next),
                onSuccess: () => {
                    setErrors({});
                    setReason('');
                },
            },
        );
    };

    return (
        <>
            <Head title={`Antifraude · ${organization.name}`} />
            <PageHeader
                title={organization.name}
                subtitle="Caso da fila de revisão do antifraude. Aceites, evidências e documentos já enviados não são alterados por nenhuma decisão."
                badge={<RiskReviewStatusBadge status={review.status} />}
                actions={
                    <Button variant="outline" asChild>
                        <Link href={adminOrganizationShow(organization.id)}>
                            Ver organização
                        </Link>
                    </Button>
                }
            />
            <RiskNav current="queue" />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="flex min-w-0 flex-col gap-4">
                    <Section title="Estado atual">
                        <div className="flex flex-wrap items-center gap-3 text-[13.5px]">
                            <RiskStatusBadge
                                status={organization.risk_status}
                            />
                            {organization.risk_status_changed_at && (
                                <span className="text-muted-foreground">
                                    desde{' '}
                                    {formatDateTime(
                                        organization.risk_status_changed_at,
                                    )}
                                </span>
                            )}
                            <span className="text-muted-foreground">
                                · caso aberto em{' '}
                                {formatDateTime(review.opened_at)} ·{' '}
                                {review.score} ponto(s)
                            </span>
                        </div>
                    </Section>

                    {review.appeal && (
                        <Section title="Pedido de revisão da organização">
                            <p className="text-muted-foreground mb-2 text-[12.5px]">
                                {review.appeal.requested_by} ·{' '}
                                {formatDateTime(review.appeal.requested_at)}{' '}
                                (LGPD art. 20)
                            </p>
                            <p className="text-[13.5px] whitespace-pre-line">
                                {review.appeal.message}
                            </p>
                        </Section>
                    )}

                    <Section title={`Sinais (${signals.length})`}>
                        {signals.length === 0 ? (
                            <p className="text-muted-foreground text-[13px]">
                                Nenhum sinal neste caso — foi aberto pelo pedido
                                de revisão.
                            </p>
                        ) : (
                            <ul className="divide-border flex flex-col divide-y">
                                {signals.map((signal) => (
                                    <li
                                        key={signal.id}
                                        className="grid gap-2 py-3 first:pt-0 last:pb-0 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-[13.5px] font-medium">
                                                    {signal.label}
                                                </span>
                                                <Badge variant="neutral">
                                                    {signal.score} pts
                                                </Badge>
                                                {!signal.can_restrict && (
                                                    <Badge variant="info">
                                                        não suspende envio
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-[12.5px]">
                                                {formatDateTime(
                                                    signal.occurred_at,
                                                )}
                                                {signal.envelope &&
                                                    ` · envelope ${signal.envelope}`}
                                            </p>
                                            {signal.explanation && (
                                                <p className="text-text-secondary mt-1 text-[12.5px]">
                                                    {signal.explanation}
                                                </p>
                                            )}
                                        </div>
                                        <EvidenceList rows={signal.evidence} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="Histórico">
                        {history.length === 0 ? (
                            <p className="text-muted-foreground text-[13px]">
                                Nenhuma mudança de estado registrada.
                            </p>
                        ) : (
                            <ol className="flex flex-col gap-3">
                                {history.map((event) => (
                                    <li key={event.id} className="text-[13px]">
                                        <div className="flex flex-wrap gap-x-2">
                                            <span className="font-medium">
                                                {event.label}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {event.actor} ·{' '}
                                                {formatDateTime(
                                                    event.occurred_at,
                                                )}
                                            </span>
                                        </div>
                                        {event.details.length > 0 && (
                                            <dl className="text-text-secondary mt-1 grid grid-cols-[auto_1fr] gap-x-2 text-[12.5px]">
                                                {event.details.map((detail) => (
                                                    <div
                                                        key={detail.label}
                                                        className="contents"
                                                    >
                                                        <dt className="text-muted-foreground">
                                                            {detail.label}
                                                        </dt>
                                                        <dd className="break-words">
                                                            {detail.value}
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Section>
                </div>

                <div className="flex min-w-0 flex-col gap-4">
                    {can_decide ? (
                        <Section title="Decisão">
                            <form
                                onSubmit={submit}
                                className="flex flex-col gap-3"
                            >
                                <div
                                    role="radiogroup"
                                    aria-label="Decisão"
                                    className="flex flex-col gap-2"
                                >
                                    {decisions.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={
                                                decision === option.value
                                            }
                                            onClick={() =>
                                                setDecision(option.value)
                                            }
                                            className={cn(
                                                'rounded-lg border px-3 py-2 text-left',
                                                decision === option.value
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-border hover:bg-muted/50',
                                            )}
                                        >
                                            <span className="block text-[13.5px] font-medium">
                                                {option.label}
                                            </span>
                                            <span className="text-muted-foreground block text-[12.5px]">
                                                {option.description}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                {errors.decision && (
                                    <p className="text-danger text-[12.5px]">
                                        {errors.decision}
                                    </p>
                                )}
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="risk-reason">
                                        Motivo (obrigatório, fica na trilha)
                                    </Label>
                                    <Textarea
                                        id="risk-reason"
                                        value={reason}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                        rows={4}
                                        maxLength={2000}
                                        aria-invalid={!!errors.reason}
                                    />
                                    {errors.reason && (
                                        <p className="text-danger text-[12.5px]">
                                            {errors.reason}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        decision === null ||
                                        reason.trim().length < min_reason
                                    }
                                >
                                    Registrar decisão
                                </Button>
                            </form>
                        </Section>
                    ) : (
                        <Section title="Decisão">
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px]">
                                <dt className="text-muted-foreground">
                                    Decisão
                                </dt>
                                <dd>{review.decision_label ?? '—'}</dd>
                                <dt className="text-muted-foreground">Por</dt>
                                <dd>{review.reviewer ?? '—'}</dd>
                                <dt className="text-muted-foreground">Em</dt>
                                <dd>
                                    {review.decided_at
                                        ? formatDateTime(review.decided_at)
                                        : '—'}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Motivo
                                </dt>
                                <dd className="whitespace-pre-line">
                                    {review.decision_reason ?? '—'}
                                </dd>
                            </dl>
                        </Section>
                    )}
                    <Section title="O que o sistema nunca faz">
                        <ul className="text-text-secondary list-disc space-y-1 pl-4 text-[12.5px]">
                            <li>
                                Invalidar aceites ou evidências já registrados.
                            </li>
                            <li>
                                Bloquear leitura, download ou assinatura de
                                documentos já enviados.
                            </li>
                            <li>Decidir sem regra, evidência e revisor.</li>
                        </ul>
                    </Section>
                </div>
            </div>
        </>
    );
}

AdminRiskShow.layout = {
    breadcrumbs: [
        { title: 'Antifraude', href: adminRiskIndex() },
        { title: 'Caso', href: '#' },
    ],
};
