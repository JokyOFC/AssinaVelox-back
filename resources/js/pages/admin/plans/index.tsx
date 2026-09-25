import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { useConfirmsPassword } from '@/components/confirms-password';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency, formatNumber } from '@/lib/format';
import {
    index as adminPlans,
    store as storePlan,
    update as updatePlan,
} from '@/routes/admin/plans';

type BillingPeriod = 'monthly' | 'yearly';

interface CatalogPlan {
    code: string;
    name: string;
    description: string | null;
    price_cents: number;
    price_formatted: string;
    billing_period: BillingPeriod;
    billing_period_label: string;
    envelope_quota: number | null;
    user_quota: number | null;
    storage_gb: number | null;
    features: Record<string, boolean>;
    extra_feature_keys: string[];
    is_active: boolean;
    is_public: boolean;
    is_sandbox: boolean;
    sort_order: number;
    is_free_plan: boolean;
    subscriptions: { active: number; total: number };
    updated_at: string | null;
}

interface CatalogEntry {
    key: string;
    group: string;
    label: string;
    description: string;
    /** `null` para itens só comerciais (sem interruptor da instalação). */
    global_enabled: boolean | null;
}

export interface AdminPlansProps {
    plans: CatalogPlan[];
    catalog: { groups: Record<string, string>; entries: CatalogEntry[] };
    billing_periods: { value: BillingPeriod; label: string }[];
    company_signature_offered: boolean;
    environment: string;
}

interface PlanFormData {
    code: string;
    name: string;
    description: string;
    /** Reais digitados ("79,90"); vira `price_cents` no envio. */
    price: string;
    billing_period: BillingPeriod;
    envelope_quota: string;
    user_quota: string;
    storage_gb: string;
    features: Record<string, boolean>;
    is_active: boolean;
    is_public: boolean;
    is_sandbox: boolean;
    sort_order: string;
}

const EMPTY: PlanFormData = {
    code: '',
    name: '',
    description: '',
    price: '0,00',
    billing_period: 'monthly',
    envelope_quota: '',
    user_quota: '',
    storage_gb: '',
    features: {},
    is_active: true,
    is_public: false,
    is_sandbox: false,
    sort_order: '10',
};

function centsToReais(cents: number): string {
    return (cents / 100).toFixed(2).replace('.', ',');
}

function reaisToCents(value: string): number {
    const normalized = value.replace(/\./g, '').replace(',', '.').trim();
    const parsed = Number.parseFloat(normalized);

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
}

function toFormData(
    plan: CatalogPlan | null,
    entries: CatalogEntry[],
): PlanFormData {
    const features: Record<string, boolean> = {};

    for (const entry of entries) {
        features[entry.key] = plan?.features[entry.key] ?? false;
    }

    if (!plan) {
        return { ...EMPTY, features };
    }

    return {
        code: plan.code,
        name: plan.name,
        description: plan.description ?? '',
        price: centsToReais(plan.price_cents),
        billing_period: plan.billing_period,
        envelope_quota:
            plan.envelope_quota === null ? '' : String(plan.envelope_quota),
        user_quota: plan.user_quota === null ? '' : String(plan.user_quota),
        storage_gb: plan.storage_gb === null ? '' : String(plan.storage_gb),
        features,
        is_active: plan.is_active,
        is_public: plan.is_public,
        is_sandbox: plan.is_sandbox,
        sort_order: String(plan.sort_order),
    };
}

function quotaLabel(value: number | null, unit: string): string {
    return value === null
        ? `${unit} ilimitados`
        : `${formatNumber(value)} ${unit}`;
}

function VisibilityBadges({ plan }: { plan: CatalogPlan }) {
    if (!plan.is_active) {
        return (
            <Badge variant="neutral" dot>
                Inativo
            </Badge>
        );
    }

    return (
        <span className="flex flex-wrap gap-1">
            {plan.is_public ? (
                <Badge variant="success" dot>
                    Público
                </Badge>
            ) : (
                <Badge variant="warning" dot>
                    Privado
                </Badge>
            )}
            {plan.is_sandbox && <Badge variant="phase">Sandbox</Badge>}
        </span>
    );
}

function SwitchRow({
    id,
    title,
    description,
    checked,
    onCheckedChange,
    disabled,
    hint,
}: {
    id: string;
    title: string;
    description?: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    hint?: React.ReactNode;
}) {
    return (
        <div className="border-muted flex items-center justify-between gap-4 border-t py-2.5 first:border-t-0">
            <div className="min-w-0">
                <Label
                    htmlFor={id}
                    className="flex flex-wrap items-center gap-2 text-[13px] font-semibold"
                >
                    {title}
                    {hint}
                </Label>
                {description && (
                    <div className="text-muted-foreground mt-0.5 text-[12px]">
                        {description}
                    </div>
                )}
            </div>
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onCheckedChange}
                disabled={disabled}
            />
        </div>
    );
}

function PlanFormDialog({
    plan,
    open,
    onClose,
    catalog,
    billingPeriods,
    companySignatureOffered,
}: {
    plan: CatalogPlan | null;
    open: boolean;
    onClose: () => void;
    catalog: AdminPlansProps['catalog'];
    billingPeriods: AdminPlansProps['billing_periods'];
    companySignatureOffered: boolean;
}) {
    const editing = plan !== null;
    const form = useForm<PlanFormData>(toFormData(plan, catalog.entries));
    const password = useConfirmsPassword({
        description:
            'Alterar o catálogo de planos muda a oferta para todos os clientes. Confirme sua senha para continuar.',
    });

    // `price` no formulário vira `price_cents` no envio: o erro volta com o nome do envio.
    const serverErrors = form.errors as Record<string, string | undefined>;

    const setFeature = (key: string, value: boolean) =>
        form.setData('features', { ...form.data.features, [key]: value });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        password.ensure(() => {
            form.transform((data) => ({
                ...(editing ? {} : { code: data.code.trim() }),
                name: data.name.trim(),
                description: data.description.trim() || null,
                price_cents: reaisToCents(data.price),
                billing_period: data.billing_period,
                envelope_quota:
                    data.envelope_quota.trim() === ''
                        ? null
                        : Number(data.envelope_quota),
                user_quota:
                    data.user_quota.trim() === ''
                        ? null
                        : Number(data.user_quota),
                storage_gb:
                    data.storage_gb.trim() === ''
                        ? null
                        : Number(data.storage_gb.replace(',', '.')),
                features: data.features,
                is_active: data.is_active,
                is_public: data.is_public,
                is_sandbox: data.is_sandbox,
                sort_order: Number(data.sort_order),
            }));

            const options = { preserveScroll: true, onSuccess: onClose };

            if (editing) {
                form.patch(updatePlan.url(plan.code), options);
            } else {
                form.post(storePlan.url(), options);
            }
        });
    };

    const groups = Object.entries(catalog.groups);

    return (
        <>
            <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
                <DialogContent className="max-h-[92svh] overflow-y-auto sm:max-w-[760px]">
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <DialogHeader>
                            <DialogTitle>
                                {editing
                                    ? `Editar plano "${plan.name}"`
                                    : 'Novo plano'}
                            </DialogTitle>
                            <DialogDescription>
                                O que estiver aqui é o que o app e o site
                                anunciam. Mudanças valem imediatamente para
                                novas contratações; quem já assina continua com
                                o plano contratado.
                            </DialogDescription>
                        </DialogHeader>

                        <section className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="plan-code">Código</Label>
                                <Input
                                    id="plan-code"
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData('code', e.target.value)
                                    }
                                    disabled={editing}
                                    placeholder="ex.: profissional_anual"
                                    aria-invalid={!!form.errors.code}
                                />
                                <InputError message={form.errors.code} />
                                {editing && (
                                    <p className="text-muted-foreground text-[12px]">
                                        Identidade do plano — não muda depois de
                                        criado.
                                    </p>
                                )}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="plan-name">Nome</Label>
                                <Input
                                    id="plan-name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    maxLength={80}
                                    aria-invalid={!!form.errors.name}
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="plan-description">
                                    Descrição curta
                                </Label>
                                <Textarea
                                    id="plan-description"
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    rows={2}
                                    maxLength={500}
                                    placeholder="Aparece embaixo do preço, no app e no site."
                                    aria-invalid={!!form.errors.description}
                                />
                                <InputError message={form.errors.description} />
                            </div>
                        </section>

                        <section className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="plan-price">Preço (R$)</Label>
                                <Input
                                    id="plan-price"
                                    inputMode="decimal"
                                    value={form.data.price}
                                    onChange={(e) =>
                                        form.setData('price', e.target.value)
                                    }
                                    disabled={plan?.is_free_plan}
                                    aria-invalid={!!serverErrors.price_cents}
                                />
                                <InputError
                                    message={serverErrors.price_cents}
                                />
                                <p className="text-muted-foreground text-[12px]">
                                    {plan?.is_free_plan
                                        ? 'O plano Grátis é o plano inicial de toda conta nova e continua gratuito.'
                                        : 'Cobrado por ciclo, sem fidelidade. "0,00" torna o plano gratuito.'}
                                </p>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="plan-period">
                                    Período de cobrança
                                </Label>
                                <Select
                                    value={form.data.billing_period}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'billing_period',
                                            value as BillingPeriod,
                                        )
                                    }
                                >
                                    <SelectTrigger id="plan-period">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {billingPeriods.map((period) => (
                                            <SelectItem
                                                key={period.value}
                                                value={period.value}
                                            >
                                                {period.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={form.errors.billing_period}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="plan-envelopes">
                                    Documentos por mês
                                </Label>
                                <Input
                                    id="plan-envelopes"
                                    inputMode="numeric"
                                    value={form.data.envelope_quota}
                                    onChange={(e) =>
                                        form.setData(
                                            'envelope_quota',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="vazio = ilimitado"
                                    aria-invalid={!!form.errors.envelope_quota}
                                />
                                <InputError
                                    message={form.errors.envelope_quota}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="plan-users">Usuários</Label>
                                <Input
                                    id="plan-users"
                                    inputMode="numeric"
                                    value={form.data.user_quota}
                                    onChange={(e) =>
                                        form.setData(
                                            'user_quota',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="vazio = ilimitado"
                                    aria-invalid={!!form.errors.user_quota}
                                />
                                <InputError message={form.errors.user_quota} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="plan-storage">
                                    Armazenamento (GB)
                                </Label>
                                <Input
                                    id="plan-storage"
                                    inputMode="decimal"
                                    value={form.data.storage_gb}
                                    onChange={(e) =>
                                        form.setData(
                                            'storage_gb',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="vazio = sem limite"
                                    aria-invalid={!!form.errors.storage_gb}
                                />
                                <InputError message={form.errors.storage_gb} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="plan-order">
                                    Ordem na lista
                                </Label>
                                <Input
                                    id="plan-order"
                                    inputMode="numeric"
                                    value={form.data.sort_order}
                                    onChange={(e) =>
                                        form.setData(
                                            'sort_order',
                                            e.target.value,
                                        )
                                    }
                                    aria-invalid={!!form.errors.sort_order}
                                />
                                <InputError message={form.errors.sort_order} />
                            </div>
                        </section>

                        <section>
                            <h3 className="mb-1 text-[13px] font-bold tracking-[.08em] uppercase">
                                Visibilidade
                            </h3>
                            <SwitchRow
                                id="plan-active"
                                title="Ativo"
                                description="Inativo some de todas as ofertas; quem já assina não é afetado."
                                checked={form.data.is_active}
                                onCheckedChange={(v) =>
                                    form.setData('is_active', v)
                                }
                                disabled={plan?.is_free_plan}
                            />
                            <SwitchRow
                                id="plan-public"
                                title="Público"
                                description="Aparece na escolha de planos do app e no site. Desligado, o plano é privado."
                                checked={form.data.is_public}
                                onCheckedChange={(v) =>
                                    form.setData('is_public', v)
                                }
                            />
                            <SwitchRow
                                id="plan-sandbox"
                                title="Sandbox (preço fictício)"
                                description="Marca o valor como placeholder de desenvolvimento. Em produção, um plano sandbox nunca é anunciado."
                                checked={form.data.is_sandbox}
                                onCheckedChange={(v) =>
                                    form.setData('is_sandbox', v)
                                }
                            />
                            <InputError message={form.errors.is_active} />
                        </section>

                        <section className="flex flex-col gap-5">
                            <div>
                                <h3 className="text-[13px] font-bold tracking-[.08em] uppercase">
                                    Recursos incluídos
                                </h3>
                                <p className="text-muted-foreground mt-0.5 text-[12.5px]">
                                    Recursos com interruptor da instalação só
                                    funcionam quando o interruptor está ligado
                                    no servidor — o selo ao lado de cada um
                                    mostra o estado atual.
                                </p>
                                <InputError message={form.errors.features} />
                            </div>
                            {groups.map(([group, label]) => (
                                <div key={group}>
                                    <div className="text-muted-foreground mb-1 text-[11px] font-bold tracking-[.12em] uppercase">
                                        {label}
                                    </div>
                                    {catalog.entries
                                        .filter(
                                            (entry) => entry.group === group,
                                        )
                                        .map((entry) => (
                                            <SwitchRow
                                                key={entry.key}
                                                id={`feature-${entry.key}`}
                                                title={entry.label}
                                                description={entry.description}
                                                checked={
                                                    form.data.features[
                                                        entry.key
                                                    ] ?? false
                                                }
                                                onCheckedChange={(v) =>
                                                    setFeature(entry.key, v)
                                                }
                                                hint={
                                                    entry.key ===
                                                        'company_signature' &&
                                                    !companySignatureOffered ? (
                                                        <Badge variant="warning">
                                                            Sem certificado
                                                            ativo
                                                        </Badge>
                                                    ) : entry.global_enabled ===
                                                      null ? null : entry.global_enabled ? (
                                                        <Badge variant="success">
                                                            Instalação: ligado
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="neutral">
                                                            Instalação:
                                                            desligado
                                                        </Badge>
                                                    )
                                                }
                                            />
                                        ))}
                                </div>
                            ))}
                            {plan && plan.extra_feature_keys.length > 0 && (
                                <p className="text-muted-foreground text-[12px]">
                                    Configurações avançadas preservadas como
                                    estão: {plan.extra_feature_keys.join(', ')}.
                                </p>
                            )}
                        </section>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                            >
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Salvar alterações' : 'Criar plano'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            {password.dialog}
        </>
    );
}

/**
 * Painel interno › Planos (docs/cobranca.md §18): catálogo de planos — o que o app e o site
 * anunciam. Criar e editar exigem senha confirmada; tudo vai para "Logs e auditoria".
 */
export default function AdminPlansIndex({
    plans,
    catalog,
    billing_periods,
    company_signature_offered,
    environment,
}: AdminPlansProps) {
    const [editing, setEditing] = useState<{ plan: CatalogPlan | null } | null>(
        null,
    );
    const production = environment === 'production';

    const columns: DataTableColumn<CatalogPlan>[] = [
        {
            key: 'plan',
            header: 'Plano',
            width: 'minmax(0,2fr)',
            cell: (plan) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-semibold">{plan.name}</span>
                    <span className="text-muted-foreground truncate text-[12px]">
                        {plan.code}
                        {plan.description ? ` · ${plan.description}` : ''}
                    </span>
                </span>
            ),
        },
        {
            key: 'price',
            header: 'Preço',
            width: 'minmax(0,1.1fr)',
            cell: (plan) => (
                <span className="flex flex-col">
                    <span className="font-semibold">
                        {plan.price_cents === 0
                            ? 'Grátis'
                            : formatCurrency(plan.price_cents)}
                    </span>
                    {plan.price_cents > 0 && (
                        <span className="text-muted-foreground text-[12px]">
                            {plan.billing_period_label.toLowerCase()}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'quotas',
            header: 'Cotas',
            width: 'minmax(0,1.6fr)',
            cell: (plan) => (
                <span className="text-text-secondary flex flex-col text-[12.5px]">
                    <span>
                        {quotaLabel(plan.envelope_quota, 'documentos/mês')}
                    </span>
                    <span>
                        {quotaLabel(plan.user_quota, 'usuários')}
                        {plan.storage_gb !== null && ` · ${plan.storage_gb} GB`}
                    </span>
                </span>
            ),
        },
        {
            key: 'features',
            header: 'Recursos',
            width: '.8fr',
            cell: (plan) => (
                <span className="text-text-secondary text-[13px]">
                    {formatNumber(
                        Object.values(plan.features).filter(Boolean).length,
                    )}{' '}
                    de {catalog.entries.length}
                </span>
            ),
        },
        {
            key: 'visibility',
            header: 'Visibilidade',
            width: 'minmax(0,1.2fr)',
            cell: (plan) => <VisibilityBadges plan={plan} />,
        },
        {
            key: 'subscriptions',
            header: 'Assinaturas',
            width: '.9fr',
            cell: (plan) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatNumber(plan.subscriptions.active)} ativas
                    {plan.subscriptions.total > plan.subscriptions.active &&
                        ` · ${formatNumber(plan.subscriptions.total)} no total`}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            width: '96px',
            align: 'right',
            cell: (plan) => (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setEditing({ plan })}
                >
                    <Pencil className="size-3.5" />
                    Editar
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Planos" />
            <PageHeader
                title="Planos"
                subtitle="O catálogo que o app e o site anunciam: preço, cotas, recursos e visibilidade de cada plano."
                actions={
                    <Button onClick={() => setEditing({ plan: null })}>
                        <Plus className="size-4" strokeWidth={2.5} />
                        Novo plano
                    </Button>
                }
            />

            {!production && (
                <p className="border-warning-border bg-warning-bg text-warning rounded-[10px] border px-3.5 py-3 text-[13px]">
                    Ambiente <strong>{environment}</strong>: planos sandbox
                    aparecem nas ofertas. Em produção, só planos ativos,
                    públicos e não sandbox são anunciados.
                </p>
            )}

            <div className="border-border bg-card shadow-card rounded-xl border">
                <DataTable
                    columns={columns}
                    rows={plans}
                    rowKey={(plan) => plan.code}
                    minWidth={960}
                    empty={
                        <EmptyState
                            variant="inline"
                            title="Nenhum plano cadastrado."
                        />
                    }
                />
            </div>

            {editing && (
                <PlanFormDialog
                    key={editing.plan?.code ?? 'new'}
                    plan={editing.plan}
                    open
                    onClose={() => setEditing(null)}
                    catalog={catalog}
                    billingPeriods={billing_periods}
                    companySignatureOffered={company_signature_offered}
                />
            )}
        </>
    );
}

AdminPlansIndex.layout = {
    breadcrumbs: [{ title: 'Planos', href: adminPlans() }],
};
