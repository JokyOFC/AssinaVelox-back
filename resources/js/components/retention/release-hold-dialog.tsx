import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { LegalHoldRow } from './types';

/**
 * Liberar uma preservação. Motivo obrigatório; a liberação fica na trilha.
 * Depois dela, a política de retenção e a exclusão manual voltam a valer.
 */
export function ReleaseHoldDialog({
    hold,
    onClose,
}: {
    hold: LegalHoldRow | null;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!hold) {
            return;
        }

        form.post(hold.release_url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={hold !== null}
            onOpenChange={(open) => {
                if (!open) {
                    form.reset();
                    form.clearErrors();
                    onClose();
                }
            }}
        >
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Liberar preservação</DialogTitle>
                        <DialogDescription>
                            {hold?.subject}. Ao liberar, a política de retenção
                            e a exclusão manual voltam a valer para o que esta
                            preservação protegia — e o que já venceu pode ser
                            apagado na próxima execução diária.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-1.5">
                        <Label htmlFor="release-reason">
                            Motivo da liberação
                        </Label>
                        <Textarea
                            id="release-reason"
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            rows={3}
                            maxLength={1000}
                            placeholder="Ex.: processo arquivado em 10/09/2026"
                            aria-invalid={!!form.errors.reason}
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing ||
                                form.data.reason.trim().length < 5
                            }
                        >
                            {form.processing && <Spinner />}
                            Liberar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
