import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import {
    AffiliateStatusBadge,
    ReferralStatusBadge,
    formatRateBp,
} from '@/components/affiliates/affiliate-badges';
import { CommissionTable } from '@/components/affiliates/commission-table';
import { ReasonDialog } from '@/components/affiliates/reason-dialog';
import { CommissionTotalsGrid } from '@/components/affiliates/totals-grid';
import { TrailList } from '@/components/affiliates/trail-list';
import type {
    AffiliateSummary,
    CommissionRow,
    CurrencyTotals,
    ProgramSettings,
    ReferralRow,
    TrailEntry,
} from '@/components/affiliates/types';
import { useConfirmsPassword } from '@/components/confirms-password';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import {
    index as adminAffiliates,
    reactivate as reactivateAffiliate,
    show as showAffiliate,
    suspend as suspendAffiliate,
} from '@/routes/admin/affiliates';
import { update as updateRate } from '@/routes/admin/affiliates/rate';
import { show as showOrganization } from '@/routes/admin/organizations';

interface Props {
    affiliate: AffiliateSummary & {
        name: string;
        email: string | null;
        has_payout_details: boolean;
    };
    totals: CurrencyTotals[];
    referrals: ReferralRow[];
    commissions: CommissionRow[];
    trail: TrailEntry[];
    program: ProgramSettings;
}

type Pending = 'suspend' | 'reactivate' | 'rate' | null;

/**
 * Painel interno › Afiliado. Taxa com trilha (antes → depois, motivo, quem), suspensão e
 * reativação com motivo e senha. Dados de repasse sempre mascarados.
 */
export default function AdminAffiliateShow({
    affiliate,
    totals,
    referrals,
    commissions,
    trail,
    program,
}: Props) {
    const [pending, setPending] = useState<Pending>(null);
    const [rate, setRate] = useState(String(affiliate.commission_rate_bp));
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const password = useConfirmsPassword({
        description:
            'Alterações no afiliado são protegidas. Confirme sua senha.',
    });

    const close = () => {
        setPending(null);
        setError(undefined);
    };

    const send = (
        method: 'post' | 'put',
        url: string,
        data: Record<string, string | number>,
    ) => {
        password.ensure(() => {
            setBusy(true);
            router[method](url, data, {
                preserveScroll: true,
                onSuccess: close,
                onError: (errors) => setError(Object.values(errors)[0]),
                onFinish: () => setBusy(false),
            });
        });
    };

    return (
        <>
            <Head title={`Afiliado · ${affiliate.name}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    size="detail"
                    leading={
                        <Button variant="ghost" size="icon-sm" asChild>
                            <Link
                                href={adminAffiliates.url()}
                                aria-label="Voltar"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                    }
                    title={affiliate.name}
                    subtitle={`${affiliate.email ?? 'Conta excluída'}${affiliate.code ? ` · código ${affiliate.code}` : ''}`}
                    badge={
                        <AffiliateStatusBadge
                            status={affiliate.status}
                            label={affiliate.status_label}
                        />
                    }
                    actions={
                        <div className="flex gap-2">
                            {(affiliate.status === 'approved' ||
                                affiliate.status === 'suspended') && (
                                <Button
                                    variant="outline"
                                    onClick={() => setPending('rate')}
                                >
                                    Alterar taxa
                                </Button>
                            )}
                            {affiliate.status === 'approved' && (
                                <Button
                                    variant="outline"
                                    onClick={() => setPending('suspend')}
                                >
                                    Suspender
                                </Button>
                            )}
                            {affiliate.status === 'suspended' && (
                                <Button
                                    onClick={() => setPending('reactivate')}
                                >
                                    Reativar
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Programa</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-[13px]">
                                <dt className="text-muted-foreground">Taxa</dt>
                                <dd>
                                    {formatRateBp(affiliate.commission_rate_bp)}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Candidatura
                                </dt>
                                <dd>{formatDate(affiliate.applied_at)}</dd>
                                <dt className="text-muted-foreground">
                                    Aprovação
                                </dt>
                                <dd>{formatDate(affiliate.approved_at)}</dd>
                                <dt className="text-muted-foreground">
                                    Termos
                                </dt>
                                <dd>{affiliate.terms_version}</dd>
                                {affiliate.status_reason && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Motivo
                                        </dt>
                                        <dd>{affiliate.status_reason}</dd>
                                    </>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Dados de repasse</CardTitle>
                            <CardDescription>
                                Cifrados em repouso; nunca exibidos por inteiro.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {affiliate.payout ? (
                                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-[13px]">
                                    <dt className="text-muted-foreground">
                                        {affiliate.payout.pix_key_type_label}
                                    </dt>
                                    <dd>{affiliate.payout.pix_key}</dd>
                                    <dt className="text-muted-foreground">
                                        Titular
                                    </dt>
                                    <dd>{affiliate.payout.holder_name}</dd>
                                    <dt className="text-muted-foreground">
                                        CPF/CNPJ
                                    </dt>
                                    <dd>{affiliate.payout.holder_tax_id}</dd>
                                </dl>
                            ) : (
                                <p className="text-muted-foreground text-[13px]">
                                    Não informados — o afiliado fica fora dos
                                    lotes.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Link</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-[13px] break-all">
                                {affiliate.link ??
                                    'Indisponível (não aprovado ou suspenso).'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <CommissionTotalsGrid totals={totals} />

                <Card>
                    <CardHeader>
                        <CardTitle>Indicações</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {referrals.length === 0 ? (
                            <p className="text-muted-foreground text-[13px]">
                                Nenhuma indicação.
                            </p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {referrals.map((referral) => (
                                    <li
                                        key={referral.id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-2.5"
                                    >
                                        <div className="min-w-0">
                                            {referral.organization_id ? (
                                                <Link
                                                    href={showOrganization.url(
                                                        referral.organization_id,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {referral.organization_name}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">
                                                    {referral.organization_name}
                                                </span>
                                            )}
                                            <p className="text-muted-foreground text-[12.5px]">
                                                {formatDate(
                                                    referral.attributed_at,
                                                )}
                                                {referral.reasons.length > 0
                                                    ? ` · ${referral.reasons.map((r) => r.label).join('; ')}`
                                                    : ''}
                                                {referral.review_note
                                                    ? ` · revisão: ${referral.review_note}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <ReferralStatusBadge
                                            status={referral.status}
                                            label={referral.status_label}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Lançamentos recentes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <CommissionTable rows={commissions} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Trilha</CardTitle>
                        <CardDescription>
                            Append-only: quem fez o quê e quando.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <TrailList entries={trail} />
                    </CardContent>
                </Card>
            </div>

            <ReasonDialog
                open={pending === 'suspend' || pending === 'reactivate'}
                onOpenChange={(open) => !open && close()}
                title={
                    pending === 'suspend'
                        ? 'Suspender afiliado'
                        : 'Reativar afiliado'
                }
                description={
                    pending === 'suspend'
                        ? 'O link deixa de atribuir novas indicações e o saldo fica fora dos lotes até a reativação.'
                        : 'O link volta a atribuir indicações e o saldo aprovado volta a entrar nos lotes.'
                }
                confirmLabel={pending === 'suspend' ? 'Suspender' : 'Reativar'}
                destructive={pending === 'suspend'}
                processing={busy}
                error={error}
                onConfirm={(reason) =>
                    send(
                        'post',
                        pending === 'suspend'
                            ? suspendAffiliate.url(affiliate.id)
                            : reactivateAffiliate.url(affiliate.id),
                        { reason },
                    )
                }
            />

            <ReasonDialog
                open={pending === 'rate'}
                onOpenChange={(open) => !open && close()}
                title="Alterar taxa de comissão"
                description="Vale para os pagamentos aprovados daqui em diante; comissões já calculadas não mudam."
                confirmLabel="Alterar taxa"
                processing={busy}
                error={error}
                onConfirm={(reason) =>
                    send('put', updateRate.url(affiliate.id), {
                        commission_rate_bp: Number(rate),
                        reason,
                    })
                }
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="rate-bp">Nova taxa (pontos-base)</Label>
                    <Input
                        id="rate-bp"
                        type="number"
                        min={0}
                        max={program.max_rate_bp}
                        value={rate}
                        onChange={(e) => setRate(e.target.value)}
                    />
                    <p className="text-muted-foreground text-[12px]">
                        Atual: {formatRateBp(affiliate.commission_rate_bp)} ·
                        nova: {rate !== '' ? formatRateBp(Number(rate)) : '—'} ·
                        máximo {formatRateBp(program.max_rate_bp)}
                    </p>
                </div>
            </ReasonDialog>

            {password.dialog}
        </>
    );
}

AdminAffiliateShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Afiliados', href: adminAffiliates() },
        {
            title: props.affiliate.name,
            href: showAffiliate(props.affiliate.id),
        },
    ],
});
