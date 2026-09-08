import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/**
 * Diálogo de confirmação (DESIGN §4.14, ações destrutivas → AlertDialog).
 * Suporta confirmação digitada (`confirmText`) e corpo extra (`children`,
 * ex.: campo de motivo).
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel = 'Confirmar',
    cancelLabel = 'Cancelar',
    destructive = false,
    processing = false,
    confirmText,
    confirmTextLabel,
    disabled = false,
    onConfirm,
    children,
    trigger,
}: {
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    destructive?: boolean;
    processing?: boolean;
    /** Texto que o usuário precisa digitar para habilitar a ação. */
    confirmText?: string;
    confirmTextLabel?: ReactNode;
    disabled?: boolean;
    onConfirm: () => void;
    children?: ReactNode;
    trigger?: ReactNode;
}) {
    const [typed, setTyped] = useState('');
    const typedOk = !confirmText || typed.trim() === confirmText;

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setTyped('');
                }

                onOpenChange?.(next);
            }}
        >
            {trigger}
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    {description && (
                        <AlertDialogDescription>
                            {description}
                        </AlertDialogDescription>
                    )}
                </AlertDialogHeader>

                {children}

                {confirmText && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="confirm-text">
                            {confirmTextLabel ?? (
                                <>
                                    Digite <b>{confirmText}</b> para confirmar
                                </>
                            )}
                        </Label>
                        <Input
                            id="confirm-text"
                            value={typed}
                            onChange={(e) => setTyped(e.target.value)}
                            autoComplete="off"
                            placeholder={confirmText}
                        />
                    </div>
                )}

                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>
                        {cancelLabel}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        disabled={processing || disabled || !typedOk}
                        onClick={(event) => {
                            event.preventDefault();
                            onConfirm();
                        }}
                        className={cn(
                            destructive &&
                                buttonVariants({ variant: 'destructive' }),
                        )}
                    >
                        {processing && <Spinner />}
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
