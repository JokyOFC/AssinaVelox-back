import { Badge } from '@/components/ui/badge';
import { formatDateTime, formatNumber } from '@/lib/format';

export interface ReconciliationSummary {
    id: string;
    status: string;
    status_label: string;
    trigger: string;
    environment: string;
    window_start: string;
    window_end: string;
    remote_count: number;
    matched_count: number;
    divergence_count: number;
    error: string | null;
    started_at: string;
    finished_at: string | null;
}

export interface MethodFamily {
    key: string;
    label: string;
    configured: boolean;
    available: boolean | null;
    offered: boolean;
}

export interface RecurringSummary {
    enabled: boolean;
    simulated: boolean;
    provider: string;
    message: string | null;
    pending: string[];
}

export interface FiscalSummary {
    enabled: boolean;
    mode: 'none' | 'simulated' | 'sefin_nacional';
    missing: string[];
}

function Panel({
    title,
    badge,
    children,
}: {
    title: string;
    badge?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        // `min-w-0`: sem isto o item da grade não encolhe abaixo da palavra mais
        // larga do conteúdo (caminhos de API do checklist da NFS-e) e a fileira
        // estoura a tela. `overflow-wrap: anywhere` quebra essas palavras.
        <section className="border-border bg-card shadow-card mb-4 flex min-w-0 break-inside-avoid flex-col gap-2.5 rounded-xl border px-5 py-4">
            <header className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[14px] font-semibold">{title}</h3>
                {badge}
            </header>
            <div className="text-text-secondary text-[12.5px] leading-[1.55] [overflow-wrap:anywhere]">
                {children}
            </div>
        </section>
    );
}

/**
 * Situação da cobrança no painel interno (Fase 2, onda D): última conciliação,
 * meios oferecidos, política de estorno, assinaturas recorrentes (classe B) e
 * NFS-e (classe B). Somente leitura; nenhum dado de documento.
 */
export function AdminBillingPanels({
    reconciliation,
    methods,
    refundPolicy,
    recurring,
    fiscal,
}: {
    reconciliation: ReconciliationSummary | null;
    methods: {
        families: MethodFamily[];
        checked_at: string | null;
        offline_expiration_hours: number;
    };
    refundPolicy: {
        owner_can_request: boolean;
        owner_window_days: number;
        max_age_days: number;
    };
    recurring: RecurringSummary;
    fiscal: FiscalSummary;
}) {
    return (
        // Colunas em vez de grade: os cartões têm alturas muito diferentes (duas
        // linhas em Conciliação, um checklist longo em NFS-e) e a grade deixaria
        // um vazio do tamanho do mais alto em cada linha.
        <div className="columns-[320px] gap-4">
            <Panel
                title="Conciliação"
                badge={
                    reconciliation && (
                        <Badge
                            variant={
                                reconciliation.status === 'failed'
                                    ? 'danger'
                                    : reconciliation.divergence_count > 0 ||
                                        reconciliation.status === 'partial'
                                      ? 'warning'
                                      : 'success'
                            }
                        >
                            {reconciliation.status_label}
                        </Badge>
                    )
                }
            >
                {reconciliation ? (
                    <>
                        Última execução em{' '}
                        {formatDateTime(reconciliation.started_at)} (
                        {reconciliation.trigger === 'admin'
                            ? 'pelo painel'
                            : 'agendada'}
                        ): {formatNumber(reconciliation.remote_count)}{' '}
                        pagamento(s) no Mercado Pago,{' '}
                        {formatNumber(reconciliation.matched_count)} com par
                        aqui, {formatNumber(reconciliation.divergence_count)}{' '}
                        divergência(s).
                        {reconciliation.error && (
                            <> Erro: {reconciliation.error}.</>
                        )}{' '}
                        A conciliação só aponta: nenhum pagamento é alterado por
                        ela.
                    </>
                ) : (
                    'Nenhuma conciliação executada ainda. Use "Conciliar agora" ou agende o job diário.'
                )}
            </Panel>

            <Panel title="Meios no checkout">
                <ul className="flex flex-col gap-1">
                    {methods.families.map((family) => (
                        <li
                            key={family.key}
                            className="flex items-center justify-between gap-2"
                        >
                            <span>{family.label}</span>
                            <Badge
                                variant={family.offered ? 'success' : 'neutral'}
                            >
                                {family.offered
                                    ? 'Oferecido'
                                    : !family.configured
                                      ? 'Desligado na configuração'
                                      : 'Inativo na conta'}
                            </Badge>
                        </li>
                    ))}
                </ul>
                <p className="mt-2">
                    {methods.checked_at
                        ? `Meios da conta consultados em ${formatDateTime(methods.checked_at)}.`
                        : 'Meios da conta ainda não consultados: vale só a configuração.'}{' '}
                    Pix e boleto vencem em{' '}
                    {formatNumber(methods.offline_expiration_hours)} h.
                </p>
            </Panel>

            <Panel title="Política de estorno">
                Estorno integral: o plano não renova e volta ao Grátis no fim do
                período, sem inadimplência; a cota usada não é devolvida.
                Parcial: plano e cota inalterados. Prazo do provedor:{' '}
                {formatNumber(refundPolicy.max_age_days)} dias.{' '}
                {refundPolicy.owner_can_request
                    ? `O proprietário pode pedir o estorno integral em até ${formatNumber(refundPolicy.owner_window_days)} dias.`
                    : 'Só a equipe da plataforma pede estornos.'}
            </Panel>

            <Panel
                title="Assinaturas recorrentes"
                badge={
                    <Badge variant={recurring.enabled ? 'warning' : 'neutral'}>
                        {recurring.enabled
                            ? 'Simulador (fora de produção)'
                            : 'Desabilitadas'}
                    </Badge>
                }
            >
                {recurring.message ??
                    'Simulador identificado ativo: nada é cobrado de verdade.'}
                <ul className="mt-2 list-disc pl-4">
                    {recurring.pending.map((item) => (
                        <li key={item}>{item}</li>
                    ))}
                </ul>
            </Panel>

            <Panel
                title="NFS-e"
                badge={
                    <Badge variant="neutral">
                        {fiscal.mode === 'simulated'
                            ? 'Simulador'
                            : fiscal.mode === 'sefin_nacional'
                              ? 'Sistema Nacional desabilitado'
                              : 'Sem provedor'}
                    </Badge>
                }
            >
                Nenhuma NFS-e é emitida hoje; o recibo interno não é documento
                fiscal. Para a emissão real falta:
                <ul className="mt-2 list-disc pl-4">
                    {fiscal.missing.map((item) => (
                        <li key={item}>{item}</li>
                    ))}
                </ul>
                <p className="mt-2">
                    Alerta: se a operadora for ME/EPP do Simples Nacional, a
                    Resolução CGSN 191/2026 obriga a emissão pelo Emissor
                    Nacional a partir de 1º/11/2026. O enquadramento é decisão
                    contábil.
                </p>
            </Panel>
        </div>
    );
}
