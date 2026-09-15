import {
    Check,
    Download,
    ExternalLink,
    FileText,
    Hourglass,
} from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import { type I18n, useI18n } from '@/i18n';
import { formatVerificationCode } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as verifyShow } from '@/routes/verify';
import type { AcceptanceAction } from '@/types/enums';

/** Comprovante do aceite, como o servidor o envia (`SignerPageProps::receipt`). */
export interface SignerReceipt {
    signed_at: string;
    verification_code: string;
    /** SHA-256 dos bytes que o signatário viu (versão apresentada). */
    document_sha256: string | null;
    /** SHA-256 do arquivo final — só existe depois da finalização. */
    signed_sha256: string | null;
    /** IP já ajustado por `settings.evidence_show_ip` (pode vir mascarado). */
    ip: string | null;
    auth_label: string;
    terms_version: string | null;
    /** Relatório de evidências (`sign.download/evidence`). */
    download_url: string | null;
    /** PDF final (`sign.download/signed`). */
    final_pdf_url: string | null;
    final_pdf_available: boolean;
    /**
     * Este navegador ainda está dentro da janela de download (arquitetura §4.7).
     * Fora dela `sign.download` responde 404 — a autorização é a janela, não a
     * posse do link do convite.
     */
    can_download: boolean;
    pending_others: number;
    /**
     * O que a plataforma afirma sobre a conclusão (`ConsentText::completionNotice`):
     * sem certificado da operadora, "aceite eletrônico com evidências".
     */
    completion_notice: string | null;
    /**
     * A coleta terminou SEM conclusão (prazo vencido, cancelamento do remetente ou
     * recusa de outro participante). Quem já assinou continua vendo o comprovante do
     * próprio aceite, mas não haverá arquivo final — e a tela precisa dizer isso.
     */
    collection_closed?: 'expired' | 'canceled' | 'refused' | null;
    /** Fase 2 §2.4: o que foi registrado (`sign`, `witness`, `approve`). */
    action?: AcceptanceAction;
    /** "Aceite eletrônico", "Aceite eletrônico como testemunha", "Aprovação eletrônica". */
    action_label?: string;
    /** Fase 2 §2.3: um item por arquivo coberto pelo aceite. */
    documents?: {
        id: string | null;
        position: number;
        name: string | null;
        sha256: string | null;
        final_pdf_url: string | null;
    }[];
}

/**
 * Data e hora em UTC — o carimbo que a declaração de aceite referencia. Em PT-BR,
 * exatamente o formato de sempre; nos demais idiomas, o estilo médio do idioma.
 */
function formatUtc(value: string, i18n: I18n): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    if (i18n.isReference) {
        const pad = (n: number) => String(n).padStart(2, '0');

        return `${pad(date.getUTCDate())}/${pad(date.getUTCMonth() + 1)}/${date.getUTCFullYear()} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
    }

    return new Intl.DateTimeFormat(i18n.bcp47, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    }).format(date);
}

export interface ReceiptCardProps {
    receipt: SignerReceipt;
    emailMasked: string;
    completed: boolean;
    /** Todos assinaram e o arquivo final está sendo preparado. */
    finalizing?: boolean;
    className?: string;
}

/**
 * Comprovante do aceite (DESIGN §6.12 fase 3; `declaracao-de-aceite.md` §4).
 *
 * O parágrafo de confirmação é o texto do documento jurídico — data local **e**
 * UTC, método de autenticação, código de verificação. O modo de conclusão vem
 * pronto do servidor (`completion_notice`): a tela nunca deduz que houve
 * assinatura criptográfica.
 */
export function ReceiptCard({
    receipt,
    emailMasked,
    completed,
    finalizing = false,
    className,
}: ReceiptCardProps) {
    const i18n = useI18n();
    const { t, tp, rich } = i18n;
    const code = formatVerificationCode(receipt.verification_code);
    const shortHash = (value: string) =>
        `${value.slice(0, 8)}…${value.slice(-4)}`;
    // Fase 2 §2.4: o aprovador registra aprovação, não assinatura.
    const approval = receipt.action === 'approve';
    const files = receipt.documents ?? [];
    const multi = files.length > 1;

    // Três estados, não dois. Quem assinava por ÚLTIMO caía no ramo "pendente" e
    // lia "você receberá o arquivo final quando todos os participantes
    // concluírem" — todos já tinham concluído, e a linha "Aguardando N
    // signatário" nem aparecia, porque N era zero.
    // Quarto estado: a coleta encerrou sem conclusão. Antes dele, quem tinha
    // assinado um envelope que depois expirou lia "o arquivo final está sendo
    // preparado" — nenhum arquivo final viria.
    const closed = receipt.collection_closed ?? null;
    const stillWaiting =
        !completed && !closed && !finalizing && receipt.pending_others > 0;
    const lastSigner =
        !completed && !closed && (finalizing || receipt.pending_others === 0);
    const closedLine =
        closed === 'expired'
            ? t('receipt.closed.expired')
            : closed === 'canceled'
              ? t('receipt.closed.canceled')
              : t('receipt.closed.refused');
    const closingLine = completed
        ? t('receipt.closing.completed')
        : closed
          ? closedLine
          : lastSigner
            ? t('receipt.closing.last_signer')
            : t('receipt.closing.waiting');

    const fileName = (file: { position: number; name: string | null }) =>
        file.name ?? t('sign.file_fallback', { position: file.position });

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            <span
                className={cn(
                    'flex size-[52px] items-center justify-center rounded-[14px]',
                    completed
                        ? 'bg-success-bg text-success-solid'
                        : 'bg-primary-soft text-primary',
                )}
            >
                {completed ? (
                    <Check className="size-6 stroke-[2.5]" />
                ) : (
                    <Hourglass className="size-6" />
                )}
            </span>

            <div>
                <h1 className="text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                    {completed
                        ? t('receipt.title.completed')
                        : closed
                          ? t('receipt.title.closed')
                          : lastSigner
                            ? t('receipt.title.last_signer')
                            : approval
                              ? t('receipt.title.approved')
                              : t('receipt.title.signed')}
                </h1>
                <p className="text-text-secondary mt-2 text-[13.5px] leading-[1.55]">
                    <b className="text-foreground">
                        {approval
                            ? t('receipt.recorded_approval')
                            : t('receipt.recorded_acceptance')}
                    </b>{' '}
                    {rich('receipt.statement', {
                        local: i18n.dateTime(receipt.signed_at),
                        utc: formatUtc(receipt.signed_at, i18n),
                        email: emailMasked,
                        code: <b className="text-foreground tabular">{code}</b>,
                        closing: closingLine,
                    })}
                </p>
                {stillWaiting && (
                    <p className="text-text-secondary mt-1.5 text-[13px]">
                        {receipt.action && receipt.action !== 'sign'
                            ? tp(
                                  'receipt.waiting_participants',
                                  receipt.pending_others,
                              )
                            : tp(
                                  'receipt.waiting_signers',
                                  receipt.pending_others,
                              )}
                    </p>
                )}
            </div>

            {receipt.completion_notice && (
                <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                    {receipt.completion_notice}
                </p>
            )}

            <dl className="border-border bg-sidebar grid grid-cols-[104px_1fr] gap-x-3 gap-y-1.5 rounded-[10px] border p-3.5 text-[12px]">
                <dt className="text-muted-foreground">
                    {t('receipt.dt.receipt')}
                </dt>
                <dd className="text-foreground font-semibold">
                    {receipt.action_label ?? t('receipt.action_fallback')}
                </dd>
                <dt className="text-muted-foreground">
                    {t('receipt.dt.datetime')}
                </dt>
                <dd className="tabular">{i18n.dateTime(receipt.signed_at)}</dd>
                <dt className="text-muted-foreground">
                    {t('receipt.dt.auth')}
                </dt>
                <dd>{receipt.auth_label}</dd>
                <dt className="text-muted-foreground">
                    {t('receipt.dt.code')}
                </dt>
                <dd className="tabular flex items-center gap-1 font-mono font-semibold">
                    {code}
                    <CopyButton
                        value={code}
                        className="size-5"
                        label={t('common.copy')}
                        toastMessage={t('common.copied')}
                        errorMessage={t('common.copy_failed')}
                    />
                </dd>
                {receipt.ip && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('receipt.dt.ip')}
                        </dt>
                        <dd className="tabular">{receipt.ip}</dd>
                    </>
                )}
                {receipt.terms_version && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('receipt.dt.terms')}
                        </dt>
                        <dd className="tabular">{receipt.terms_version}</dd>
                    </>
                )}
                {multi && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('receipt.dt.files')}
                        </dt>
                        <dd className="flex min-w-0 flex-col gap-1">
                            {files.map((file) => (
                                <span
                                    key={`${file.position}-${file.id}`}
                                    className="flex min-w-0 flex-col"
                                >
                                    <span className="truncate font-semibold">
                                        {t('sign.file_label', {
                                            position: file.position,
                                            name: fileName(file),
                                        })}
                                    </span>
                                    {file.sha256 && (
                                        <span className="flex items-center gap-1 font-mono">
                                            {shortHash(file.sha256)}
                                            <CopyButton
                                                value={file.sha256}
                                                className="size-5"
                                                label={t('common.copy')}
                                                toastMessage={t(
                                                    'common.copied',
                                                )}
                                                errorMessage={t(
                                                    'common.copy_failed',
                                                )}
                                            />
                                        </span>
                                    )}
                                </span>
                            ))}
                        </dd>
                    </>
                )}
                {!multi && receipt.document_sha256 && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('receipt.dt.document_sha')}
                        </dt>
                        <dd className="flex items-center gap-1 font-mono break-all">
                            {shortHash(receipt.document_sha256)}
                            <CopyButton
                                value={receipt.document_sha256}
                                className="size-5"
                                label={t('common.copy')}
                                toastMessage={t('common.copied')}
                                errorMessage={t('common.copy_failed')}
                            />
                        </dd>
                    </>
                )}
                {receipt.signed_sha256 && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('receipt.dt.final_sha')}
                        </dt>
                        <dd className="flex items-center gap-1 font-mono break-all">
                            {shortHash(receipt.signed_sha256)}
                            <CopyButton
                                value={receipt.signed_sha256}
                                className="size-5"
                                label={t('common.copy')}
                                toastMessage={t('common.copied')}
                                errorMessage={t('common.copy_failed')}
                            />
                        </dd>
                    </>
                )}
            </dl>

            {!receipt.can_download && (
                <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                    {t('receipt.window_closed')}
                </p>
            )}

            {/*
             * `verification_code` pode chegar vazio (envelope sem código, caso de
             * borda de dados antigos): sem código não existe página pública para
             * apontar, e um link para /verificar/ vazio seria pior que nenhum.
             */}
            {receipt.verification_code !== '' && (
                <p className="text-muted-foreground text-[12px] leading-[1.5]">
                    {rich('receipt.verify_note', {
                        link: (
                            <a
                                href={verifyShow(receipt.verification_code).url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary inline-flex items-center gap-1 font-semibold hover:underline"
                            >
                                {t('receipt.verify_link', { code })}
                                <ExternalLink className="size-3" />
                            </a>
                        ),
                    })}
                </p>
            )}

            {/* Fase 2 §2.3: com vários arquivos, uma cópia final por arquivo. */}
            {multi &&
                receipt.can_download &&
                files.some((file) => file.final_pdf_url) && (
                    <div className="flex flex-col gap-1.5">
                        {files.map(
                            (file) =>
                                file.final_pdf_url && (
                                    <Button
                                        key={`final-${file.position}`}
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="justify-start"
                                    >
                                        <a href={file.final_pdf_url}>
                                            <Download className="size-4" />
                                            <span className="truncate">
                                                {t('receipt.download_file', {
                                                    position: file.position,
                                                    name:
                                                        file.name ??
                                                        t(
                                                            'receipt.file_fallback_lower',
                                                            {
                                                                position:
                                                                    file.position,
                                                            },
                                                        ),
                                                })}
                                            </span>
                                        </a>
                                    </Button>
                                ),
                        )}
                    </div>
                )}

            <div className="flex flex-wrap gap-2">
                {multi &&
                receipt.can_download &&
                files.some(
                    (file) => file.final_pdf_url,
                ) ? null : receipt.can_download &&
                  receipt.final_pdf_available &&
                  receipt.final_pdf_url ? (
                    <Button
                        asChild
                        variant="outline"
                        size="lg"
                        className="flex-1"
                    >
                        <a href={receipt.final_pdf_url}>
                            <Download className="size-4" />
                            {t('receipt.download_copy')}
                        </a>
                    </Button>
                ) : (
                    <Button
                        variant="outline"
                        size="lg"
                        className="flex-1"
                        disabled
                    >
                        <Download className="size-4" />
                        {closed
                            ? t('receipt.no_final')
                            : t('receipt.available_when_signed')}
                    </Button>
                )}
                {/*
                 * O comprovante de evidências é do PRÓPRIO aceite desta pessoa e existe a
                 * partir do instante em que ele é gravado — o backend só exige o aceite
                 * (`Sign\DownloadController`). Ele não depende da finalização, que é o que
                 * produz o PDF final. Condicioná-lo a `final_pdf_available` deixava quem
                 * acabou de assinar sem nenhuma forma de guardar a prova do que fez.
                 */}
                {receipt.can_download && receipt.download_url && (
                    <Button
                        asChild
                        variant="outline"
                        size="lg"
                        className="flex-1"
                    >
                        <a href={receipt.download_url}>
                            <FileText className="size-4" />
                            {t('receipt.evidence_report')}
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
}
