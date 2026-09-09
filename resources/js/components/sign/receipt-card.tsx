import { Check, Download, FileText, Hourglass } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatVerificationCode, plural } from '@/lib/format';
import { cn } from '@/lib/utils';

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
}

/** Data e hora em UTC — o carimbo que a declaração de aceite referencia. */
function formatUtc(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const pad = (n: number) => String(n).padStart(2, '0');

    return `${pad(date.getUTCDate())}/${pad(date.getUTCMonth() + 1)}/${date.getUTCFullYear()} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
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
    const code = formatVerificationCode(receipt.verification_code);
    const shortHash = (value: string) =>
        `${value.slice(0, 8)}…${value.slice(-4)}`;

    // Três estados, não dois. Quem assinava por ÚLTIMO caía no ramo "pendente" e
    // lia "você receberá o arquivo final quando todos os participantes
    // concluírem" — todos já tinham concluído, e a linha "Aguardando N
    // signatário" nem aparecia, porque N era zero.
    const stillWaiting =
        !completed && !finalizing && receipt.pending_others > 0;
    const closingLine = completed
        ? 'O arquivo final já está disponível.'
        : finalizing || receipt.pending_others === 0
          ? 'Todos os participantes assinaram; o arquivo final está sendo preparado.'
          : 'Você receberá o arquivo final quando todos os participantes concluírem.';

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
                        ? 'Documento concluído'
                        : finalizing || receipt.pending_others === 0
                          ? 'Aceites concluídos'
                          : 'Você já assinou'}
                </h1>
                <p className="text-text-secondary mt-2 text-[13.5px] leading-[1.55]">
                    <b className="text-foreground">Aceite registrado.</b> Sua
                    manifestação foi gravada em{' '}
                    {formatDateTime(receipt.signed_at)} (
                    {formatUtc(receipt.signed_at)} UTC), autenticada por código
                    enviado ao e-mail {emailMasked}. Código de verificação:{' '}
                    <b className="text-foreground tabular">{code}</b>.{' '}
                    {closingLine}
                </p>
                {stillWaiting && (
                    <p className="text-text-secondary mt-1.5 text-[13px]">
                        Aguardando{' '}
                        {plural(receipt.pending_others, 'signatário')}.
                    </p>
                )}
            </div>

            {receipt.completion_notice && (
                <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                    {receipt.completion_notice}
                </p>
            )}

            <dl className="border-border bg-sidebar grid grid-cols-[104px_1fr] gap-x-3 gap-y-1.5 rounded-[10px] border p-3.5 text-[12px]">
                <dt className="text-muted-foreground">Comprovante</dt>
                <dd className="text-foreground font-semibold">
                    Aceite eletrônico
                </dd>
                <dt className="text-muted-foreground">Data e hora</dt>
                <dd className="tabular">{formatDateTime(receipt.signed_at)}</dd>
                <dt className="text-muted-foreground">Autenticação</dt>
                <dd>{receipt.auth_label}</dd>
                <dt className="text-muted-foreground">Código</dt>
                <dd className="tabular flex items-center gap-1 font-mono font-semibold">
                    {code}
                    <CopyButton value={code} className="size-5" />
                </dd>
                {receipt.ip && (
                    <>
                        <dt className="text-muted-foreground">IP registrado</dt>
                        <dd className="tabular">{receipt.ip}</dd>
                    </>
                )}
                {receipt.terms_version && (
                    <>
                        <dt className="text-muted-foreground">Texto aceito</dt>
                        <dd className="tabular">{receipt.terms_version}</dd>
                    </>
                )}
                {receipt.document_sha256 && (
                    <>
                        <dt className="text-muted-foreground">
                            SHA-256 do documento
                        </dt>
                        <dd className="flex items-center gap-1 font-mono break-all">
                            {shortHash(receipt.document_sha256)}
                            <CopyButton
                                value={receipt.document_sha256}
                                className="size-5"
                            />
                        </dd>
                    </>
                )}
                {receipt.signed_sha256 && (
                    <>
                        <dt className="text-muted-foreground">SHA-256 final</dt>
                        <dd className="flex items-center gap-1 font-mono break-all">
                            {shortHash(receipt.signed_sha256)}
                            <CopyButton
                                value={receipt.signed_sha256}
                                className="size-5"
                            />
                        </dd>
                    </>
                )}
            </dl>

            {!receipt.can_download && (
                <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                    A janela de download desta sessão terminou. Por segurança, o
                    comprovante e o arquivo final só são entregues a quem acabou
                    de confirmar o código enviado por e-mail — a posse do link
                    do convite não basta. Quando o documento for concluído, você
                    receberá por e-mail um link próprio para baixar o arquivo.
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                {receipt.can_download &&
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
                            Baixar cópia
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
                        Disponível quando todos assinarem
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
                            Relatório de evidências
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
}
