import { usePage } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { bulk as dossiersBulk } from '@/routes/dossiers';
import { store as dossierStore } from '@/routes/envelopes/dossier';
import type { EnvelopeStatus } from '@/types/enums';
import { DossierExportDialog } from './dossier-export-dialog';
import { useDossierExport } from './use-dossier-export';

/** A flag `dossier_export` (global E plano) — ausente = desligada (roadmap T8). */
export function useDossierFeature(): boolean {
    return usePage().props.features?.dossier_export === true;
}

/**
 * "Baixar dossiê (ZIP)" do detalhe e das evidências (roadmap §2.13). Só aparece com a
 * flag ligada e com o documento concluído (o servidor recusa os demais).
 */
export function DossierButton({
    envelopeId,
    status,
    variant = 'outline',
    size = 'default',
    className,
}: {
    envelopeId: string;
    status: EnvelopeStatus;
    variant?: 'outline' | 'success';
    size?: 'default' | 'sm';
    className?: string;
}) {
    const enabled = useDossierFeature();
    const [open, setOpen] = useState(false);
    const dossier = useDossierExport();

    if (!enabled || status !== 'completed') {
        return null;
    }

    const start = () => {
        setOpen(true);

        if (
            !dossier.requesting &&
            (dossier.current === null ||
                dossier.current.status === 'expired' ||
                dossier.current.status === 'failed')
        ) {
            void dossier.request(dossierStore(envelopeId).url);
        }
    };

    return (
        <>
            <Button
                type="button"
                variant={variant}
                size={size}
                onClick={start}
                className={className}
            >
                <Archive className="size-[15px]" />
                Baixar dossiê (ZIP)
            </Button>
            <DossierExportDialog
                open={open}
                onOpenChange={setOpen}
                current={dossier.current}
                error={dossier.error}
                requesting={dossier.requesting}
                stalled={dossier.stalled}
                onRetry={dossier.retry}
            />
        </>
    );
}

/**
 * "Baixar dossiês" da barra de seleção da lista (Q12): um ZIP com um dossiê por documento.
 * A visibilidade é conferida no pedido e de novo na montagem, no servidor.
 */
export function BulkDossierButton({
    ids,
    disabled = false,
}: {
    ids: string[];
    disabled?: boolean;
}) {
    const enabled = useDossierFeature();
    const [open, setOpen] = useState(false);
    const [count, setCount] = useState(0);
    const dossier = useDossierExport();

    if (!enabled) {
        return null;
    }

    const start = () => {
        setCount(ids.length);
        setOpen(true);
        void dossier.request(dossiersBulk().url, { ids });
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                size="xs"
                onClick={start}
                disabled={disabled || ids.length === 0}
            >
                <Archive className="size-3.5" /> Baixar dossiês
            </Button>
            <DossierExportDialog
                open={open}
                onOpenChange={(value) => {
                    setOpen(value);

                    if (!value) {
                        dossier.reset();
                    }
                }}
                current={dossier.current}
                error={dossier.error}
                requesting={dossier.requesting}
                stalled={dossier.stalled}
                onRetry={dossier.retry}
                bulkCount={count}
            />
        </>
    );
}
