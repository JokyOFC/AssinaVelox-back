import { Head, useForm } from '@inertiajs/react';
import { Archive, HardDrive, Search, ShieldCheck } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import { CategoryPeriodField } from '@/components/retention/category-period-field';
import { LegalHoldList } from '@/components/retention/legal-hold-list';
import { PlaceHoldDialog } from '@/components/retention/place-hold-dialog';
import { ReleaseHoldDialog } from '@/components/retention/release-hold-dialog';
import {
    describeDays,
    type LegalHoldRow,
    type RetentionCategoryKey,
    type RetentionDeletionPreview,
    type RetentionSettingsProps,
} from '@/components/retention/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { formatDateTime, plural } from '@/lib/format';
import {
    general as settingsGeneral,
    retention as settingsRetention,
} from '@/routes/settings';
import { update as updateRetention } from '@/routes/settings/retention';
import { store as storeSettingsHold } from '@/routes/settings/retention/holds';

type PeriodInputs = Record<RetentionCategoryKey, string>;

const COUNT_LABELS: Record<string, [string, string]> = {
    files: ['arquivo', 'arquivos'],
    documents: ['documento', 'documentos'],
    recipients: ['participante', 'participantes'],
    identity_captures: ['foto', 'fotos'],
    dossiers: ['dossiê', 'dossiês'],
    audit_trail: ['evento da trilha', 'eventos da trilha'],
};

function summarizeCounts(counts: Record<string, number>): string {
    const parts = Object.entries(COUNT_LABELS)
        .filter(([key]) => (counts[key] ?? 0) > 0)
        .map(([key, [one, many]]) => plural(counts[key], one, many));

    return parts.length > 0 ? parts.join(' · ') : '—';
}

/**
 * Configurações › Retenção e preservação (Fase 2 §2.19 —
 * docs/fase-2/retencao-e-preservacao.md). Prazos por categoria com o mínimo da
 * operadora, explicação do que é apagado e do que fica, confirmação forte ao
 * reduzir prazos ou ativar a exclusão automática, e as preservações.
 */
export default function SettingsRetention(props: RetentionSettingsProps) {
    if (!props.enabled) {
        return (
            <>
                <Head title="Configurações · Retenção" />
                <Phase2EmptyState
                    title="Retenção e preservação"
                    description="Defina por quanto tempo a organização guarda documentos, fotos e dossiês, e preserve o que não pode ser excluído. Disponível quando o recurso estiver ativo no seu plano."
                    ctaHref={settingsGeneral().url}
                    ctaLabel="Voltar para Configurações"
                />
            </>
        );
    }

    return <RetentionSettings {...props} />;
}

function RetentionSettings(
    props: Extract<RetentionSettingsProps, { enabled: true }>,
) {
    const {
        can,
        policy,
        categories,
        limits,
        confirmation_phrase,
        verification,
        backups,
        holds,
        folders,
        recent_deletions,
        deletion_preview,
    } = props;

    const initialPeriods = useMemo(
        () =>
            Object.fromEntries(
                categories.map((category) => [
                    category.key,
                    policy.periods[category.key] === null
                        ? ''
                        : String(policy.periods[category.key]),
                ]),
            ) as PeriodInputs,
        [categories, policy.periods],
    );

    const form = useForm<{
        is_active: boolean;
        periods: PeriodInputs;
        confirmation: string;
    }>({
        is_active: policy.is_active,
        periods: initialPeriods,
        confirmation: '',
    });

    const [confirming, setConfirming] = useState(false);
    // Prévia com os prazos do FORMULÁRIO, refeita ao abrir a confirmação. Enquanto não
    // chega (ou se a consulta falhar), a tela mostra a prévia com os prazos salvos.
    const [preview, setPreview] = useState<RetentionDeletionPreview | null>(
        null,
    );
    const [previewFailed, setPreviewFailed] = useState(false);
    const [placing, setPlacing] = useState(false);
    const [releasing, setReleasing] = useState<LegalHoldRow | null>(null);

    const parsed = (key: RetentionCategoryKey): number | null => {
        const raw = form.data.periods[key]?.trim() ?? '';

        return raw === '' ? null : Number(raw);
    };

    const reduced = categories.filter((category) => {
        const value = parsed(category.key);
        const saved = policy.periods[category.key];

        return (
            category.available &&
            value !== null &&
            Number.isFinite(value) &&
            (saved === null || value < saved)
        );
    });

    const anyPeriod = categories.some(
        (category) => parsed(category.key) !== null,
    );
    const activating = form.data.is_active && !policy.is_active && anyPeriod;
    const needsConfirmation =
        activating || (form.data.is_active && reduced.length > 0);

    useEffect(() => {
        if (!confirming) {
            return;
        }

        const query = new URLSearchParams();

        Object.entries(form.data.periods).forEach(([key, value]) => {
            if (value.trim() !== '') {
                query.set(`periods[${key}]`, value.trim());
            }
        });

        let cancelled = false;
        setPreview(null);
        setPreviewFailed(false);

        fetch(`${deletion_preview.endpoint}?${query.toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        })
            .then((response) =>
                response.ok
                    ? (response.json() as Promise<RetentionDeletionPreview>)
                    : Promise.reject(new Error(String(response.status))),
            )
            .then((body) => {
                if (!cancelled) {
                    setPreview(body);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPreviewFailed(true);
                }
            });

        return () => {
            cancelled = true;
        };
        // A prévia é refeita a cada abertura, com os prazos daquele momento.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [confirming]);

    const shownPreview = preview ?? deletion_preview;

    const send = (confirmation: string) => {
        form.transform((data) => ({
            is_active: data.is_active,
            confirmation,
            periods: Object.fromEntries(
                Object.entries(data.periods).map(([key, value]) => [
                    key,
                    value.trim() === '' ? null : Number(value),
                ]),
            ),
        }));

        form.put(updateRetention.url(), {
            preserveScroll: true,
            onSuccess: () => setConfirming(false),
            onError: (errors) => {
                if (!errors.confirmation) {
                    setConfirming(false);
                }
            },
        });
    };

    const save = (event: FormEvent) => {
        event.preventDefault();

        if (needsConfirmation) {
            setConfirming(true);

            return;
        }

        send('');
    };

    const activeHolds = holds.filter((hold) => hold.active).length;

    return (
        <>
            <Head title="Configurações · Retenção" />

            <form
                onSubmit={save}
                className="flex flex-col gap-4"
                aria-label="Política de retenção"
            >
                <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Exclusão automática"
                        description="Uma vez por dia, o sistema apaga o que passou do prazo em cada categoria abaixo. Documentos em andamento nunca são apagados, e nada sob preservação sai."
                        action={
                            policy.is_active ? (
                                <Badge variant="success" dot>
                                    Ativa
                                </Badge>
                            ) : (
                                <Badge variant="neutral" dot>
                                    Desativada
                                </Badge>
                            )
                        }
                    />

                    <div className="flex items-start gap-3">
                        <Switch
                            id="retention-active"
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked === true)
                            }
                            disabled={!can.configure}
                        />
                        <div className="grid gap-0.5">
                            <Label htmlFor="retention-active">
                                Apagar automaticamente pelos prazos abaixo
                            </Label>
                            <p className="text-muted-foreground text-[12.5px]">
                                Desativada, os prazos ficam salvos mas nada é
                                apagado.
                            </p>
                        </div>
                    </div>

                    {policy.updated_at && (
                        <p className="text-muted-foreground text-[12px]">
                            Última alteração em{' '}
                            {formatDateTime(policy.updated_at)}
                            {policy.updated_by
                                ? ` por ${policy.updated_by}`
                                : ''}
                            .
                        </p>
                    )}
                </section>

                <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Prazos por categoria"
                        description="Em dias. Deixe em branco para nunca apagar automaticamente naquela categoria. Os mínimos são definidos pela operadora a partir de obrigações legais e não podem ser reduzidos."
                    />

                    <div className="flex flex-col gap-3">
                        {categories.map((category) => (
                            <CategoryPeriodField
                                key={category.key}
                                category={category}
                                value={form.data.periods[category.key] ?? ''}
                                saved={policy.periods[category.key]}
                                maxDays={limits.max_days}
                                disabled={!can.configure}
                                error={
                                    (form.errors as Record<string, string>)[
                                        `periods.${category.key}`
                                    ]
                                }
                                onChange={(value) =>
                                    form.setData('periods', {
                                        ...form.data.periods,
                                        [category.key]: value,
                                    })
                                }
                            />
                        ))}
                    </div>

                    <InputError message={form.errors.confirmation} />

                    <div className="flex flex-wrap items-center justify-end gap-3">
                        {needsConfirmation && (
                            <span className="text-warning text-[12.5px] font-semibold">
                                Esta alteração passa a apagar dados: será pedida
                                uma confirmação.
                            </span>
                        )}
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                !form.isDirty ||
                                !can.configure
                            }
                        >
                            {form.processing && <Spinner />}
                            Salvar política
                        </Button>
                    </div>
                </section>

                <section className="border-border bg-card shadow-card grid gap-4 rounded-xl border p-5 md:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <p className="flex items-center gap-2 text-[14px] font-semibold">
                            <Search aria-hidden className="size-4" />
                            Verificação pública depois da exclusão
                        </p>
                        <p className="text-[13px] font-medium">
                            {verification.label}
                        </p>
                        <p className="text-muted-foreground text-[12.5px]">
                            {verification.description} Regra definida pela
                            operadora para toda a plataforma.
                        </p>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <p className="flex items-center gap-2 text-[14px] font-semibold">
                            <HardDrive aria-hidden className="size-4" />
                            Cópias de segurança
                        </p>
                        <p className="text-muted-foreground text-[12.5px]">
                            {backups.text}
                        </p>
                    </div>
                </section>
            </form>

            <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                <Heading
                    variant="small"
                    title={
                        <span className="flex items-center gap-2">
                            <ShieldCheck aria-hidden className="size-4" />
                            Preservações
                            {activeHolds > 0 && (
                                <Badge variant="info">
                                    {plural(activeHolds, 'ativa', 'ativas')}
                                </Badge>
                            )}
                        </span>
                    }
                    description="Uma preservação (bloqueio de exclusão) impede que qualquer coisa coberta por ela seja apagada — pela retenção, manualmente ou pela exclusão da conta — até ser liberada. Para um único documento, use o detalhe do documento."
                    action={
                        can.manage_holds ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setPlacing(true)}
                            >
                                Nova preservação
                            </Button>
                        ) : undefined
                    }
                />

                <LegalHoldList
                    holds={holds}
                    onRelease={can.manage_holds ? setReleasing : undefined}
                    emptyText="Nenhuma preservação registrada nesta organização."
                />

                {!can.manage_holds && (
                    <p className="text-muted-foreground text-[12px]">
                        Criar e liberar preservações é permitido ao proprietário
                        e a quem pode alterar as configurações e ver todos os
                        documentos da conta.
                    </p>
                )}
            </section>

            <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                <Heading
                    variant="small"
                    title={
                        <span className="flex items-center gap-2">
                            <Archive aria-hidden className="size-4" />
                            Exclusões recentes
                        </span>
                    }
                    description="Recibos da exclusão automática. Guardam só a categoria, a data e as contagens — nenhum conteúdo, nome ou e-mail."
                />

                {recent_deletions.length === 0 ? (
                    <p className="text-muted-foreground text-[13px]">
                        Nada foi apagado pela política de retenção até agora.
                    </p>
                ) : (
                    <ul className="divide-border flex flex-col divide-y">
                        {recent_deletions.map((deletion) => (
                            <li
                                key={deletion.id}
                                className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13px] first:pt-0 last:pb-0"
                            >
                                <div className="flex min-w-0 flex-col">
                                    <span className="font-medium">
                                        {deletion.category_label}
                                    </span>
                                    <span className="text-muted-foreground text-[12px]">
                                        {summarizeCounts(deletion.counts)}
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    {deletion.status !== 'completed' && (
                                        <Badge variant="warning">
                                            Arquivos pendentes
                                        </Badge>
                                    )}
                                    <span className="text-muted-foreground text-[12px]">
                                        {formatDateTime(deletion.purged_at)}
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title="Confirmar exclusão automática"
                destructive
                processing={form.processing}
                confirmText={confirmation_phrase}
                confirmLabel="Salvar e confirmar"
                description="Com a política ativa, o que já passou do novo prazo será apagado de forma definitiva na próxima execução diária. Não há como desfazer: arquivos, participantes e registros saem do sistema, e as cópias de segurança são substituídas no ciclo de rotação."
                onConfirm={() => send(confirmation_phrase)}
            >
                <ul className="bg-muted flex list-disc flex-col gap-1 rounded-md py-2 pr-3 pl-7 text-[13px]">
                    {activating && (
                        <li>A exclusão automática passa a ficar ativa.</li>
                    )}
                    {reduced.map((category) => {
                        const value = parsed(category.key) ?? 0;
                        const saved = policy.periods[category.key];

                        return (
                            <li key={category.key}>
                                <b>{category.label}</b>:{' '}
                                {saved === null
                                    ? `passa a apagar depois de ${describeDays(value)}`
                                    : `de ${describeDays(saved)} para ${describeDays(value)}`}
                            </li>
                        );
                    })}
                </ul>
                <div className="border-border flex flex-col gap-1.5 rounded-md border p-3 text-[13px]">
                    <p className="font-semibold">
                        {preview === null && !previewFailed
                            ? 'Calculando o que seria apagado…'
                            : shownPreview.organization_held
                              ? 'A organização inteira está preservada: nada será apagado enquanto a preservação estiver ativa.'
                              : shownPreview.total === 0
                                ? 'Hoje, nada passou dos prazos: nada seria apagado na próxima execução.'
                                : `Na próxima execução seriam apagados ${plural(shownPreview.total, 'item', 'itens')}:`}
                    </p>
                    {previewFailed && (
                        <p className="text-text-secondary text-[12.5px]">
                            Não foi possível refazer a conta com os prazos do
                            formulário; os números abaixo usam os prazos salvos.
                        </p>
                    )}
                    {!shownPreview.organization_held &&
                        shownPreview.total > 0 && (
                            <ul className="flex list-disc flex-col gap-0.5 pl-5">
                                {categories
                                    .filter(
                                        (category) =>
                                            (shownPreview.counts[
                                                category.key
                                            ] ?? 0) > 0,
                                    )
                                    .map((category) => (
                                        <li key={category.key}>
                                            <b>{category.label}</b>:{' '}
                                            <span className="tabular">
                                                {shownPreview.counts[
                                                    category.key
                                                ] ?? 0}
                                            </span>
                                        </li>
                                    ))}
                            </ul>
                        )}
                    {shownPreview.held > 0 && (
                        <p className="text-text-secondary text-[12.5px]">
                            {plural(
                                shownPreview.held,
                                'documento preservado fica',
                                'documentos preservados ficam',
                            )}{' '}
                            de fora.
                        </p>
                    )}
                    <p className="text-text-secondary text-[12.5px]">
                        Próxima execução:{' '}
                        <b className="tabular">
                            {formatDateTime(shownPreview.next_run_at)}
                        </b>
                        .
                    </p>
                </div>
                <InputError message={form.errors.confirmation} />
            </ConfirmDialog>

            <PlaceHoldDialog
                open={placing}
                onOpenChange={setPlacing}
                action={storeSettingsHold.url()}
                title="Nova preservação"
                description="Escolha o que preservar. Enquanto a preservação estiver ativa, nada coberto por ela é excluído — nem pela retenção, nem manualmente, nem pela exclusão da conta. A criação fica registrada com o seu nome."
                folders={folders}
            />
            <ReleaseHoldDialog
                hold={releasing}
                onClose={() => setReleasing(null)}
            />
        </>
    );
}

SettingsRetention.layout = {
    breadcrumbs: [
        { title: 'Configurações', href: settingsGeneral() },
        { title: 'Retenção e preservação', href: settingsRetention() },
    ],
};
