import { useState, type ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * Confirmação com motivo obrigatório (trilha do programa). `onConfirm` recebe o motivo e
 * uma função para exibir o erro de validação devolvido pelo servidor.
 */
export function ReasonDialog({
    open,
    onOpenChange,
    title,
    description,
    label = 'Motivo',
    confirmLabel,
    destructive = false,
    processing = false,
    error,
    children,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    label?: string;
    confirmLabel: string;
    destructive?: boolean;
    processing?: boolean;
    error?: string;
    children?: ReactNode;
    onConfirm: (reason: string) => void;
}) {
    const [reason, setReason] = useState('');

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setReason('');
                }

                onOpenChange(next);
            }}
            title={title}
            description={description}
            confirmLabel={confirmLabel}
            destructive={destructive}
            processing={processing}
            disabled={reason.trim().length < 5}
            onConfirm={() => onConfirm(reason.trim())}
        >
            <div className="grid gap-3">
                {children}
                <div className="grid gap-1.5">
                    <Label htmlFor="reason-dialog-text">{label}</Label>
                    <Textarea
                        id="reason-dialog-text"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        rows={3}
                        maxLength={500}
                        aria-invalid={!!error}
                    />
                    {error && (
                        <p className="text-danger text-[12.5px]">{error}</p>
                    )}
                </div>
            </div>
        </ConfirmDialog>
    );
}
