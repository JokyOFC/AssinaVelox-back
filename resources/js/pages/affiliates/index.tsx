import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, Handshake, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import {
    AffiliateStatusBadge,
    ReferralStatusBadge,
    formatRateBp,
} from '@/components/affiliates/affiliate-badges';
import { CommissionTable } from '@/components/affiliates/commission-table';
import {
    EMPTY_PAYOUT,
    PayoutDetailsFields,
} from '@/components/affiliates/payout-details-form';
import { SimplePagination } from '@/components/affiliates/simple-pagination';
import { CommissionTotalsGrid } from '@/components/affiliates/totals-grid';
import type {
    AffiliateSummary,
    CommissionRow,
    CommissionStatus,
    CurrencyTotals,
    PayoutFormData,
    ProgramSettings,
    ReferralRow,
} from '@/components/affiliates/types';
import { useConfirmsPassword } from '@/components/confirms-password';
import { CopyButton } from '@/components/copy-button';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatCurrency, formatDate } from '@/lib/format';
import {
    apply as applyRoute,
    index as affiliatesIndex,
} from '@/routes/affiliates';
import { exportMethod as exportCommissions } from '@/routes/affiliates/commissions';
import { update as updatePayoutRoute } from '@/routes/affiliates/payout';
import { review as reviewReferral } from '@/routes/affiliates/referrals';
import type { Paginated } from '@/types';

interface Props {
    program: ProgramSettings;
    affiliate: AffiliateSummary | null;
    totals: CurrencyTotals[];
    referrals: ReferralRow[];
    commissions: Paginated<CommissionRow> | null;
    filters: { status: CommissionStatus | 'all' };
}

const STATUS_FILTERS: { value: CommissionStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'Todas' },
    { value: 'pending', label: 'Pendentes' },
    { value: 'approved', label: 'Aprovadas' },
    { value: 'paid', label: 'Pagas' },
    { value: 'reversed', label: 'Revertidas' },
];

function ProgramRules({ program }: { program: ProgramSettings }) {
    return (
        <ul className="text-muted-foreground list-disc space-y-1 pl-5 text-[13px]">
            <li>
                Cada organização indicada pelo seu link, que se cadastrar em até{' '}
                {program.attribution_window_days} dias depois do clique, fica
                vinculada a você (vale o primeiro link aberto dentro desse
                prazo).
            </li>
            <li>
                A comissão é calculada sobre pagamentos aprovados dessa
                organização
                {program.commission_months
                    ? ` durante ${program.commission_months} meses`
                    : ''}
                , com a taxa definida pela equipe na sua aprovação (sugerida:{' '}
                {formatRateBp(program.default_rate_bp)}).
            </li>
            <li>
                Ela fica pendente por {program.approval_hold_days} dias (prazo
                de estorno). Estornos e contestações revertem a comissão, mesmo
                depois de aprovada.
            </li>
            <li>
                O AssinaVelox calcula as comissões; o repasse é feito pela
                equipe, fora da plataforma, para saldos a partir de{' '}
                {formatCurrency(program.min_payout_cents)}.
            </li>
            <li>
                Indicar a si mesmo (mesmo usuário, e-mail, domínio corporativo
                ou rede) não gera comissão. Toda decisão automática pode ser
                revista por uma pessoa da equipe.
            </li>
        </ul>
    );
}

function ApplicationForm({ program }: { program: ProgramSettings }) {
    const form = useForm<PayoutFormData & { terms: boolean }>({
        ...EMPTY_PAYOUT,
        terms: false,
    });

    const errors = form.errors as Partial<Record<string, string>>;

    return (
        <form
            className="grid gap-5"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(applyRoute.url(), { preserveScroll: true });
            }}
        >
            <PayoutDetailsFields
                data={form.data}
                onChange={(key, value) => form.setData(key, value)}
                errors={errors}
                keyTypes={program.pix_key_types}
            />
            <div className="grid gap-1.5">
                <div className="flex items-start gap-2">
                    <Checkbox
                        id="terms"
                        checked={form.data.terms}
                        onCheckedChange={(value) =>
                            form.setData('terms', value === true)
                        }
                    />
                    <Label htmlFor="terms" className="leading-snug font-normal">
                        Li e aceito os termos do programa de afiliados (versão{' '}
                        {program.terms_version}).
                    </Label>
                </div>
                <InputError message={errors.terms ?? errors.application} />
            </div>
            <div>
                <Button type="submit" disabled={form.processing}>
                    Enviar candidatura
                </Button>
            </div>
        </form>
    );
}

function PayoutUpdateDialog({
    open,
    onOpenChange,
    program,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    program: ProgramSettings;
}) {
    const form = useForm<PayoutFormData>({ ...EMPTY_PAYOUT });
    const password = useConfirmsPassword({
        description:
            'Alterar os dados de repasse é uma ação protegida. Confirme sua senha.',
    });

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Atualizar dados de repasse</DialogTitle>
                        <DialogDescription>
                            Por segurança, os dados atuais nunca são exibidos
                            por inteiro. Informe todos os campos novamente.
                        </DialogDescription>
                    </DialogHeader>
                    <PayoutDetailsFields
                        data={form.data}
                        onChange={(key, value) => form.setData(key, value)}
                        errors={form.errors as Partial<Record<string, string>>}
                        keyTypes={program.pix_key_types}
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            disabled={form.processing}
                            onClick={() =>
                                password.ensure(() =>
                                    form.put(updatePayoutRoute.url(), {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            form.reset();
                                            onOpenChange(false);
                                        },
                                    }),
                                )
                            }
                        >
                            Salvar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {password.dialog}
        </>
    );
}

function ReferralList({ referrals }: { referrals: ReferralRow[] }) {
    if (referrals.length === 0) {
        return (
            <p className="text-muted-foreground text-[13px]">
                Nenhuma organização indicada ainda.
            </p>
        );
    }

    return (
        <ul className="divide-border divide-y">
            {referrals.map((referral) => (
                <li key={referral.id} className="flex flex-col gap-1.5 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="font-medium">
                            {referral.organization_name}
                        </span>
                        <ReferralStatusBadge
                            status={referral.status}
                            label={referral.status_label}
                        />
                    </div>
                    <span className="text-muted-foreground text-[12.5px]">
                        Indicada em {formatDate(referral.attributed_at)}
                        {referral.expires_at
                            ? ` · comissões até ${formatDate(referral.expires_at)}`
                            : ''}
                    </span>
                    {referral.reasons.length > 0 && (
                        <span className="text-muted-foreground text-[12.5px]">
                            Regra automática:{' '}
                            {referral.reasons.map((r) => r.label).join('; ')}
                            {referral.reviewed_at
                                ? ` · revisada em ${formatDate(referral.reviewed_at)}`
                                : referral.review_requested_at
                                  ? ' · revisão humana solicitada'
                                  : ''}
                        </span>
                    )}
                    {referral.can_request_review && (
                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        reviewReferral.url(referral.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Pedir revisão humana
                            </Button>
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * Portal do afiliado (Fase 3 §3.10). O sistema calcula; o repasse é manual, feito pela
 * equipe fora da plataforma. Indicados aparecem só pelo nome da organização.
 */
export default function AffiliatesIndex({
    program,
    affiliate,
    totals,
    referrals,
    commissions,
    filters,
}: Props) {
    const [payoutOpen, setPayoutOpen] = useState(false);
    const canApply = affiliate === null || affiliate.status === 'rejected';
    const active =
        affiliate !== null &&
        (affiliate.status === 'approved' || affiliate.status === 'suspended');

    return (
        <>
            <Head title="Programa de afiliados" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow="Parcerias"
                    title="Programa de afiliados"
                    subtitle="Indique o AssinaVelox e receba comissão sobre os pagamentos aprovados das organizações indicadas."
                    badge={
                        affiliate ? (
                            <AffiliateStatusBadge
                                status={affiliate.status}
                                label={affiliate.status_label}
                            />
                        ) : undefined
                    }
                />

                {affiliate?.status === 'pending' && (
                    <Alert>
                        <Handshake />
                        <AlertTitle>Candidatura em análise</AlertTitle>
                        <AlertDescription>
                            Enviada em {formatDate(affiliate.applied_at)}. A
                            equipe AssinaVelox vai aprovar e definir sua taxa de
                            comissão.
                        </AlertDescription>
                    </Alert>
                )}

                {affiliate?.status === 'suspended' && (
                    <Alert variant="destructive">
                        <ShieldAlert />
                        <AlertTitle>Participação suspensa</AlertTitle>
                        <AlertDescription>
                            Seu link não atribui novas indicações e os repasses
                            ficam retidos até a revisão.{' '}
                            {affiliate.status_reason ?? ''}
                        </AlertDescription>
                    </Alert>
                )}

                {canApply && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {affiliate?.status === 'rejected'
                                    ? 'Nova candidatura'
                                    : 'Quero ser afiliado'}
                            </CardTitle>
                            <CardDescription>
                                {affiliate?.status === 'rejected'
                                    ? `A candidatura anterior foi recusada${affiliate.status_reason ? `: ${affiliate.status_reason}` : '.'}`
                                    : 'Como funciona:'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-6">
                            <ProgramRules program={program} />
                            <ApplicationForm program={program} />
                        </CardContent>
                    </Card>
                )}

                {active && affiliate && (
                    <>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Seu link de indicação</CardTitle>
                                    <CardDescription>
                                        Código {affiliate.code} · taxa{' '}
                                        {formatRateBp(
                                            affiliate.commission_rate_bp,
                                        )}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {affiliate.link ? (
                                        <div className="bg-muted flex items-center gap-2 rounded-md px-3 py-2">
                                            <code className="min-w-0 flex-1 truncate text-[13px]">
                                                {affiliate.link}
                                            </code>
                                            <CopyButton
                                                value={affiliate.link}
                                                toastMessage="Link copiado"
                                            />
                                        </div>
                                    ) : (
                                        <p className="text-muted-foreground text-[13px]">
                                            O link fica indisponível enquanto a
                                            participação estiver suspensa.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Dados de repasse</CardTitle>
                                    <CardDescription>
                                        Exibidos sempre mascarados.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap items-end justify-between gap-3">
                                    {affiliate.payout ? (
                                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px]">
                                            <dt className="text-muted-foreground">
                                                {affiliate.payout.method} (
                                                {
                                                    affiliate.payout
                                                        .pix_key_type_label
                                                }
                                                )
                                            </dt>
                                            <dd>{affiliate.payout.pix_key}</dd>
                                            <dt className="text-muted-foreground">
                                                Titular
                                            </dt>
                                            <dd>
                                                {affiliate.payout.holder_name} ·{' '}
                                                {affiliate.payout.holder_tax_id}
                                            </dd>
                                        </dl>
                                    ) : (
                                        <p className="text-muted-foreground text-[13px]">
                                            Nenhum dado cadastrado.
                                        </p>
                                    )}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setPayoutOpen(true)}
                                    >
                                        Atualizar
                                    </Button>
                                </CardContent>
                            </Card>
                        </div>

                        <CommissionTotalsGrid totals={totals} />

                        <Card>
                            <CardHeader>
                                <CardTitle>Organizações indicadas</CardTitle>
                                <CardDescription>
                                    Só o nome da organização é exibido.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ReferralList referrals={referrals} />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                <div>
                                    <CardTitle>Lançamentos</CardTitle>
                                    <CardDescription>
                                        Comissões, ajustes e estornos de
                                        comissão.
                                    </CardDescription>
                                </div>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={exportCommissions.url()}>
                                        <Download /> Exportar CSV
                                    </a>
                                </Button>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                <div className="flex flex-wrap gap-2">
                                    {STATUS_FILTERS.map((option) => (
                                        <Button
                                            key={option.value}
                                            size="sm"
                                            variant={
                                                filters.status === option.value
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            asChild
                                        >
                                            <Link
                                                href={affiliatesIndex.url({
                                                    query:
                                                        option.value === 'all'
                                                            ? {}
                                                            : {
                                                                  status: option.value,
                                                              },
                                                })}
                                                preserveScroll
                                                preserveState
                                            >
                                                {option.label}
                                            </Link>
                                        </Button>
                                    ))}
                                </div>
                                {commissions && (
                                    <>
                                        <CommissionTable
                                            rows={commissions.data}
                                        />
                                        <SimplePagination page={commissions} />
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        <PayoutUpdateDialog
                            open={payoutOpen}
                            onOpenChange={setPayoutOpen}
                            program={program}
                        />
                    </>
                )}
            </div>
        </>
    );
}

AffiliatesIndex.layout = {
    breadcrumbs: [{ title: 'Programa de afiliados', href: affiliatesIndex() }],
};
