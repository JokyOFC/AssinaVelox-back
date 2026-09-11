import { Archive, CircleAlert, Clock, Download, RefreshCw } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
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
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime, plural } from '@/lib/format';
import { dossierStatusTones } from '@/lib/labels';
import type { DossierExport } from '@/types/signatures';

/**
 * Janela do dossiê ZIP: estado da geração assíncrona, link com expiração e o que o
 * pacote contém (docs/fase-2/carimbo-e-dossie.md §5.1). Fechar a janela não cancela o
 * pedido; pedir de novo o mesmo documento reaproveita o pacote (idempotente).
 */
export function DossierExportDialog({
    open,
    onOpenChange,
    current,
    error,
    requesting,
    stalled,
    onRetry,
    bulkCount,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    current: DossierExport | null;
    error: string | null;
    requesting: boolean;
    stalled: boolean;
    onRetry: () => void;
    /** Presente no "Baixar" em lote: quantos documentos foram selecionados. */
    bulkCount?: number;
}) {
    const bulk = bulkCount !== undefined;
    const status = current?.status ?? null;
    const preparing =
        requesting || status === 'pending' || status === 'building';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[520px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Archive className="size-4" />
                        {bulk
                            ? `Dossiês de ${plural(bulkCount, 'documento')} (ZIP)`
                            : 'Dossiê do documento (ZIP)'}
                    </DialogTitle>
                    <DialogDescription>
                        Um pacote para guardar ou entregar a quem precisa
                        conferir o documento fora da plataforma.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-3" aria-live="polite">
                    {preparing && (
                        <div className="border-primary-soft-border bg-primary-soft text-primary flex items-start gap-2.5 rounded-[10px] border p-3 text-[13px] leading-[1.5]">
                            <Spinner className="mt-0.5 size-4 shrink-0" />
                            <div>
                                <p className="font-semibold">
                                    {requesting
                                        ? 'Enviando o pedido…'
                                        : (current?.status_label ??
                                          'Em preparação')}
                                </p>
                                <p className="opacity-90">
                                    Montar o pacote pode levar alguns minutos.
                                    Você pode fechar esta janela: o pedido
                                    continua, e pedir de novo reaproveita o
                                    mesmo dossiê.
                                </p>
                            </div>
                        </div>
                    )}

                    {stalled && preparing && (
                        <p className="text-warning text-[12.5px]">
                            A preparação está demorando mais que o normal. Feche
                            a janela e peça de novo mais tarde.
                        </p>
                    )}

                    {current && status === 'ready' && (
                        <div className="border-success-border bg-success-bg flex flex-col gap-2.5 rounded-[10px] border p-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant={dossierStatusTones.ready}>
                                    {current.status_label}
                                </Badge>
                                {current.size_bytes !== null && (
                                    <span className="text-text-secondary tabular text-[12.5px]">
                                        {formatBytes(current.size_bytes)}
                                    </span>
                                )}
                            </div>
                            {current.download_url && (
                                <Button asChild variant="success" size="sm">
                                    {/* Exatamente a URL assinada recebida do servidor. */}
                                    <a href={current.download_url}>
                                        <Download className="size-[15px]" />
                                        Baixar ZIP
                                    </a>
                                </Button>
                            )}
                            {current.expires_at && (
                                <p className="text-text-secondary flex items-start gap-1.5 text-[12.5px]">
                                    <Clock className="mt-0.5 size-3.5 shrink-0" />
                                    <span>
                                        O link vence em{' '}
                                        <b className="tabular">
                                            {formatDateTime(current.expires_at)}
                                        </b>
                                        . Depois disso o arquivo é apagado e é
                                        preciso pedir outro.
                                    </span>
                                </p>
                            )}
                            {current.sha256 && (
                                <div className="text-text-secondary text-[12px]">
                                    <span className="font-semibold">
                                        SHA-256 do ZIP
                                    </span>
                                    <span className="mt-0.5 flex min-w-0 items-start gap-1">
                                        <code className="min-w-0 font-mono text-[11.5px] break-all">
                                            {current.sha256}
                                        </code>
                                        <CopyButton
                                            value={current.sha256}
                                            label="Copiar resumo do ZIP"
                                            className="size-5 shrink-0"
                                        />
                                    </span>
                                </div>
                            )}
                            {current.timestamp_label && (
                                <p className="text-text-secondary text-[12px] leading-[1.5]">
                                    {current.timestamp_label}
                                </p>
                            )}
                        </div>
                    )}

                    {(status === 'failed' || status === 'expired' || error) && (
                        <div
                            role="alert"
                            className="border-danger-border bg-danger-bg text-danger flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]"
                        >
                            <CircleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>
                                {error ??
                                    (status === 'expired'
                                        ? 'O link deste dossiê venceu e o arquivo foi apagado. Peça de novo para gerar outro.'
                                        : (current?.error ??
                                          'Não foi possível montar o dossiê. Tente novamente.'))}
                            </span>
                        </div>
                    )}

                    <DossierContents bulk={bulk} />
                </div>

                <DialogFooter>
                    {(status === 'failed' || status === 'expired' || error) && (
                        <Button
                            variant="outline"
                            onClick={onRetry}
                            disabled={requesting}
                        >
                            <RefreshCw className="size-[15px]" />
                            {status === 'expired'
                                ? 'Gerar de novo'
                                : 'Tentar de novo'}
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Fechar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** "O que vem no pacote" — espelho da §5.1 do contrato (e só isto). */
function DossierContents({ bulk }: { bulk: boolean }) {
    return (
        <details className="border-border rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
            <summary className="cursor-pointer font-semibold">
                O que vem no pacote
            </summary>
            <ul className="text-text-secondary mt-2 flex list-disc flex-col gap-1 pl-4">
                {bulk && (
                    <li>
                        Um dossiê completo por documento concluído, com um
                        índice. Documentos que não estão concluídos ficam de
                        fora e aparecem no índice com o motivo.
                    </li>
                )}
                <li>
                    Todas as versões guardadas de cada arquivo: o original, o
                    consolidado, o relatório de evidências, o arquivo final e as
                    revisões assinadas, quando houver.
                </li>
                <li>
                    A trilha de auditoria completa, em JSON e em planilha (CSV).
                </li>
                <li>Os resultados da validação das assinaturas.</li>
                <li>
                    Um manifesto com o resumo SHA-256 de cada arquivo e um
                    LEIA-ME explicando como conferir.
                </li>
                <li>
                    Quando a operadora mantém o serviço ligado, um carimbo do
                    tempo sobre o manifesto, que é o carimbo do tempo da
                    operadora e não um carimbo ICP-Brasil.
                </li>
            </ul>
            <p className="text-muted-foreground mt-2">
                O pacote não traz senhas, códigos de acesso, PIN, certificados,
                imagens de assinatura nem fotos. Endereços IP e e-mails seguem a
                política de exibição da organização.
            </p>
        </details>
    );
}
